import importlib.util
from pathlib import Path
import unittest
from unittest.mock import patch

spec = importlib.util.spec_from_file_location('worker', Path(__file__).with_name('worker.py'))
worker = importlib.util.module_from_spec(spec)
spec.loader.exec_module(worker)


class QueueWorkerTest(unittest.TestCase):
    def job(self):
        return {'id': 3, 'attempts': 1, 'snapshot': {'published': True, 'sku': 'example', 'patris': 'example',
                'owner': 'example', 'missing': True, 'copy_hash': 'hash'},
                'product': {'url': 'https://digitalogic.ir/product/example/'}}

    def test_identity_conflict_never_calls_generator_or_publisher(self):
        job = self.job(); job['snapshot']['patris'] = 'another'
        with patch.object(worker, 'generate') as generate, patch.object(worker, 'wp') as wp:
            self.assertEqual('blocked', worker.content(job)['status'])
            generate.assert_not_called(); wp.assert_not_called()

    def test_unindexed_is_waiting_not_success(self):
        with patch.object(worker, 'public_seo', return_value={'http': 200}), patch.object(worker, 'google_inspect', return_value={'verdict': 'FAIL'}):
            result = worker.seo(self.job())
            self.assertEqual('waiting_google', result['status'])
            self.assertFalse(result['result']['indexing_request_submitted'])

    def test_google_failure_retains_public_readiness_and_retries(self):
        with patch.object(worker, 'public_seo', return_value={'http': 200}), patch.object(worker, 'google_inspect', side_effect=OSError()):
            result = worker.seo(self.job())
            self.assertEqual('retry', result['status'])
            self.assertEqual(200, result['result']['public_readiness']['http'])

    def test_noindex_and_wrong_canonical_stop_google_inspection(self):
        for body in (b'<meta name="robots" content="noindex">', b'<link rel="canonical" href="https://other.example/">'):
            with patch.object(worker, 'fetch', return_value=(body, 'text/html')):
                with self.assertRaises(ValueError): worker.public_seo(self.job()['product']['url'])

    def test_already_indexed_old_copy_still_waits_for_recrawl(self):
        job = self.job(); job['product']['copy_verified_at'] = '2026-11-18T04:00:00+00:00'
        google = {'verdict': 'PASS', 'lastCrawlTime': '2026-11-17T22:00:00Z'}
        with patch.object(worker, 'public_seo', return_value={'http': 200}), patch.object(worker, 'google_inspect', return_value=google):
            result = worker.seo(job)
            self.assertEqual('waiting_google', result['status'])
            self.assertTrue(result['result']['currently_indexed'])
            self.assertFalse(result['result']['latest_copy_crawled'])

    def test_unverified_source_blocks_without_publication(self):
        draft = {'action': 'publish', 'evidence': [{'url': 'https://manufacturer.example/source'}]}
        with patch.object(worker, 'generate', return_value=draft), patch.object(worker, 'source_evidence', side_effect=ValueError('Manufacturer host needs review')), patch.object(worker, 'wp') as wp:
            result = worker.content(self.job())
            self.assertEqual('blocked', result['status'])
            self.assertEqual('source_verification_required', result['result']['reason'])
            wp.assert_not_called()


if __name__ == '__main__': unittest.main()
