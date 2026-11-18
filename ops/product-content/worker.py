#!/usr/bin/python3
"""Bounded server worker; AI drafts only, the WP publisher owns all writes."""
import argparse
import fcntl
import hashlib
import importlib.machinery
import importlib.util
import json
import os
from pathlib import Path
import re
import subprocess
import tempfile
import urllib.request
import urllib.error
from urllib.parse import urlsplit
import xml.etree.ElementTree as ET

ROOT = Path('/var/lib/digitalogic-product-content')
INSTALL = Path('/opt/digitalogic-product-content')
WP = ['/usr/sbin/runuser', '-u', 'www-data', '--', '/usr/local/bin/wp',
      '--path=/var/www/wp', '--user=1', 'digitalogic', 'content-queue']
HEADERS = {'User-Agent': 'Digitalogic-Content-Readiness/1.0'}


def wp(command, payload=None, *args):
    proc = subprocess.run(WP + [command] + list(args), input=json.dumps(payload) if payload is not None else None,
                          text=True, encoding='utf-8', capture_output=True, timeout=120)
    if proc.returncode:
        raise RuntimeError('WordPress queue command failed: ' + command)
    return json.loads(proc.stdout)


def fetch(url):
    # Public evidence only. Block private/loopback addresses and off-domain redirects.
    import ipaddress
    import socket
    parsed = urlsplit(url)
    if parsed.scheme != 'https' or parsed.username or parsed.password or parsed.port not in (None, 443):
        raise ValueError('HTTPS public URL required')
    for entry in socket.getaddrinfo(parsed.hostname, 443):
        # The pinned storefront resolves locally on its own host; no other private target is allowed.
        if parsed.hostname != 'digitalogic.ir' and not ipaddress.ip_address(entry[4][0]).is_global:
            raise ValueError('Private evidence address rejected')
    class NoRedirect(urllib.request.HTTPRedirectHandler):
        def redirect_request(self, req, fp, code, msg, headers, newurl):
            raise ValueError('Evidence redirect requires explicit review')
    # Reuse the installed international egress; the pinned local storefront stays direct.
    proxies = {} if parsed.hostname == 'digitalogic.ir' else {'https': 'http://localhost:8118'}
    opener = urllib.request.build_opener(urllib.request.ProxyHandler(proxies), NoRedirect())
    with opener.open(urllib.request.Request(url, headers=HEADERS), timeout=35) as response:
        if response.status != 200:
            raise ValueError('Public fetch not successful')
        body = response.read(4 * 1024 * 1024 + 1)
        if len(body) > 4 * 1024 * 1024:
            raise ValueError('Public evidence too large')
        return body, response.headers.get_content_type()


def source_evidence(entries):
    if not entries or len(entries) > 8:
        raise ValueError('Manufacturer evidence required')
    evidence = []
    allowed = json.loads((INSTALL / 'manufacturer-hosts.json').read_text())
    for item in entries:
        host = urlsplit(item['url']).hostname or ''
        if not any(host == domain or host.endswith('.' + domain) for domain in allowed):
            raise ValueError('Manufacturer host needs review')
        body, content_type = fetch(item['url'])
        if content_type == 'application/pdf' or body.startswith(b'%PDF'):
            with tempfile.TemporaryDirectory(dir=ROOT) as folder:
                pdf = Path(folder) / 'source.pdf'
                pdf.write_bytes(body)
                text = subprocess.run(['/usr/bin/pdftotext', str(pdf), '-'], capture_output=True,
                                      timeout=40, check=True).stdout.decode('utf-8', 'replace')
        else:
            import html
            text = html.unescape(re.sub('<[^>]+>', ' ', body.decode('utf-8', 'replace')))
        quote = ' '.join(item.get('source_excerpt', '').split())
        normalized = ' '.join(text.split())
        if len(quote) < 30 or quote.casefold() not in normalized.casefold():
            raise ValueError('Manufacturer excerpt not verified against direct source')
        evidence.append(dict(url=item['url'], facts=item['facts'],
                             source_excerpt=quote[:600], sha256=hashlib.sha256(body).hexdigest()))
    return evidence


def public_copy_check(job, copy):
    body, _ = fetch(job['product']['url'])
    text = body.decode('utf-8', 'replace')
    import html
    normalized = re.sub(r'\s+', ' ', html.unescape(text))
    excerpt = html.unescape(copy['short_description']).strip()
    if excerpt and excerpt not in normalized:
        raise ValueError('Public product excerpt readback failed')
    if html.unescape(copy['seo_description']) not in normalized:
        raise ValueError('Public SEO description readback failed')


def generate(job):
    public_input = dict(job['product'])
    public_input.pop('source_name', None)
    prompt = (INSTALL / 'draft-prompt.txt').read_text() + '\nPUBLIC_PRODUCT_INPUT\n' + json.dumps(public_input, ensure_ascii=False)
    with tempfile.TemporaryDirectory(dir=ROOT) as folder:
        output = Path(folder) / 'draft.json'
        log = Path(folder) / 'generation.log'
        with log.open('w') as stream:
            # Drafting has web search and a read-only shell, but no connected mutation/contact tools.
            proc = subprocess.run(['/root/.local/bin/codex', '--no-daemon', '--search', '--disable', 'plugins', '--disable', 'apps', '-s', 'read-only', '-a', 'never',
                                   '-C', str(INSTALL), 'exec', '--skip-git-repo-check', '--color', 'never',
                                   '--output-schema', str(INSTALL / 'draft-schema.json'), '-o', str(output), '-'],
                                  input=prompt, text=True, stdout=stream, stderr=stream, timeout=1800)
        if proc.returncode or not output.is_file():
            raise RuntimeError('Draft generation failed; no product write attempted')
        result = json.loads(output.read_text())
        # Keep bounded private research evidence; never record auth tokens or raw tool logs.
        (ROOT / f"draft-{job['id']}.json").write_text(json.dumps(result, ensure_ascii=False))
        return result


def content(job):
    snap = job['snapshot']
    if not snap or not snap['published'] or not snap['sku'] or any(snap[key] and snap[key] != snap['sku'] for key in ('patris', 'owner')):
        return dict(status='blocked', retry_seconds=86400, result={'reason': 'exact_identity_required'})
    if not snap['missing']:
        public_copy_check(job, {'short_description': job['product']['short_description'],
                                'seo_description': job['product']['seo_description']})
        return dict(status='done', result={'reason': 'existing_content_preserved', 'copy_hash': snap['copy_hash']})
    draft = generate(job)
    if draft['action'] == 'blocked':
        return dict(status='blocked', retry_seconds=604800, result={'reason': draft['reason'], 'unknowns': draft['unknowns']})
    evidence = source_evidence(draft['evidence'])
    payload = dict(id=job['id'], token=job['token'], snapshot=snap, copy=draft['copy'],
                   evidence=evidence, unknowns=draft['unknowns'])
    backups = ROOT / 'backups'
    backups.mkdir(mode=0o700, exist_ok=True)
    backup = backups / f"product-{job['product_id']}-job-{job['id']}.json"
    if not backup.exists():
        with backup.open('x') as stream:
            json.dump({'product': job['product'], 'snapshot': snap}, stream, ensure_ascii=False)
    wp('publish', payload)
    public_copy_check(job, draft['copy'])
    return dict(status='done', result={'reason': 'published_and_publicly_verified',
                                      'sources': [item['url'] for item in evidence], 'unknowns': draft['unknowns']})


def public_seo(url):
    body, _ = fetch(url)
    text = body.decode('utf-8', 'replace')
    import html
    class Parser(__import__('html.parser', fromlist=['HTMLParser']).HTMLParser):
        def __init__(self):
            super().__init__(); self.canonical = None; self.noindex = False; self.description = False
        def handle_starttag(self, tag, attrs):
            a = dict(attrs)
            if tag == 'link' and a.get('rel') == 'canonical': self.canonical = a.get('href')
            if tag == 'meta' and a.get('name', '').lower() in ('robots', 'googlebot') and 'noindex' in a.get('content', '').lower(): self.noindex = True
            if tag == 'meta' and a.get('name') == 'description' and a.get('content', '').strip(): self.description = True
    parser = Parser(); parser.feed(text)
    if parser.noindex or parser.canonical != url or not parser.description:
        raise ValueError('Product canonical/indexability/description check failed')
    index, _ = fetch('https://digitalogic.ir/sitemap_index.xml')
    ns = {'s': 'http://www.sitemaps.org/schemas/sitemap/0.9'}
    maps = [n.text for n in ET.fromstring(index).findall('s:sitemap/s:loc', ns)]
    product_maps = [u for u in maps if urlsplit(u).netloc == 'digitalogic.ir' and re.search(r'/product-sitemap\d*\.xml$', u)]
    if not product_maps or len(product_maps) > 25:
        raise ValueError('Product sitemap index missing or unexpectedly large')
    for sitemap in product_maps:
        content, _ = fetch(sitemap)
        urls = [n.text for n in ET.fromstring(content).findall('s:url/s:loc', ns)]
        if url in urls:
            return {'http': 200, 'canonical': url, 'indexable': True, 'sitemap': sitemap}
    raise ValueError('Product missing from public product sitemaps')


def google_inspect(url):
    # Reuse the installed importer and its root-only machine credential. No new grants.
    loader = importlib.machinery.SourceFileLoader('gsc', '/usr/local/libexec/digitalogic-search-console-import')
    spec = importlib.util.spec_from_loader(loader.name, loader)
    module = importlib.util.module_from_spec(spec); loader.exec_module(module)
    os.environ['HTTPS_PROXY'] = 'http://localhost:8118'
    # Rebuild urllib after changing the scoped Google proxy environment.
    urllib.request.install_opener(urllib.request.build_opener())
    credential = json.loads(Path('/etc/digitalogic-monitoring/search-console/service-account.json').read_text())
    token = module.access_token(credential)
    result = module.request(module.INSPECT_URL, json.dumps({'inspectionUrl': url, 'siteUrl': module.PROPERTY,
                                                          'languageCode': 'en-US'}).encode(), module.authorization(token))
    status = result.get('inspectionResult', {}).get('indexStatusResult', {})
    return {key: status.get(key) for key in ('verdict', 'coverageState', 'indexingState', 'lastCrawlTime', 'googleCanonical', 'userCanonical')}


def seo(job):
    if not job['snapshot'] or not job['snapshot']['published']:
        return dict(status='blocked', retry_seconds=86400, result={'reason': 'not_published'})
    readiness = public_seo(job['product']['url'])
    # Read-only URL inspection is distinct from submitting an indexing request.
    try:
        google = google_inspect(job['product']['url'])
    except (urllib.error.URLError, OSError, ValueError, KeyError) as error:
        return dict(status='retry', retry_seconds=21600,
                    result={'public_readiness': readiness, 'google': {'state': 'inspection_failed', 'http': getattr(error, 'code', None)}})
    indexed = google.get('verdict') == 'PASS'
    return dict(status='done' if indexed else 'waiting_google',
                retry_seconds=min(604800, 86400 * max(1, job['attempts'])),
                result={'public_readiness': readiness, 'google': google, 'indexing_request_submitted': False})


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('stage', choices=['content', 'seo', 'reconcile'])
    parser.add_argument('--limit', type=int, default=2)
    parser.add_argument('--id', type=int)
    args = parser.parse_args()
    ROOT.mkdir(mode=0o700, parents=True, exist_ok=True)
    os.umask(0o077)
    with (ROOT / f'{args.stage}.lock').open('w') as lock:
        try: fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError: return
        if args.stage == 'reconcile':
            print(json.dumps(wp('reconcile', None, '--limit=200')))
            status = wp('status')
            (ROOT / 'status.json').write_text(json.dumps(status))
            import time
            from datetime import datetime
            lines = ['digitalogic_content_queue_observed_seconds ' + str(int(time.time()))]
            if status['last_full_sweep']:
                lines.append('digitalogic_content_queue_last_full_sweep_seconds ' + str(int(datetime.fromisoformat(status['last_full_sweep']).timestamp())))
            for row in status['counts']:
                lines.append('digitalogic_content_queue_jobs{stage="' + row['stage'] + '",status="' + row['status'] + '"} ' + str(row['count']))
            target = Path('/var/lib/node_exporter/textfile_collector/digitalogic-content-queue.prom')
            if target.parent.is_dir():
                temporary = target.with_suffix('.prom.tmp')
                temporary.write_text('\n'.join(lines) + '\n'); temporary.chmod(0o644); temporary.replace(target)
            return
        for _ in range(max(1, min(args.limit, 10))):
            flags = [f'--stage={args.stage}'] + ([f'--id={args.id}'] if args.id else [])
            job = wp('claim', None, *flags)
            if job is None: break
            try:
                outcome = content(job) if args.stage == 'content' else seo(job)
            except Exception as error:
                outcome = dict(status='retry', result={'reason': type(error).__name__, 'detail': str(error)[:180] if isinstance(error, ValueError) else 'Worker failed; claim retained for bounded retry'})
            try:
                wp('finish', dict(id=job['id'], token=job['token'], **outcome))
            except RuntimeError:
                print(json.dumps({'job': job['id'], 'stage': args.stage, 'status': 'claim_changed_or_expired'})); continue
            print(json.dumps({'job': job['id'], 'stage': args.stage, 'status': outcome['status']}), flush=True)


if __name__ == '__main__':
    main()
