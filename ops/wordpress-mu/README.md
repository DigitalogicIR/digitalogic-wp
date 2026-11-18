# Retired WordPress deployment adapters

Digitalogic no longer ships separate MU deployment adapters. Their canonical
implementations live in the `digitalogic-wp` plugin and are loaded by its main
bootstrap. Production migration must preserve the former files only in a
server-side rollback archive, outside `wp-content/mu-plugins`, and remove them
from the active plugin surface after parity and live acceptance are verified.

## Protected human-contact discovery

The maintained CLI-only implementation is
`includes/cli/class-digitalogic-human-contacts-cli.php`, loaded by the main
plugin. It exposes only administrator-authorized `list` and `get` commands,
rejects command-specific options, and reconstructs bounded safe projections
without private routing or account identifiers. Availability observations are
historical and always include their recorded timestamp and
`point_in_time_only: true`.

Do not restore a separate `digitalogic-human-contacts.php` MU runtime. Follow
`docs/plugin-consolidation.md` for the main-plugin rollout and server-side
rollback archive. A remaining legacy MU class can mask the canonical command
until that migration is completed. Source merge does not approve production
deployment, registry changes, or contacting a person. The private option must
never be copied into this repository or overwritten during rollout or rollback.

See `docs/CLI.md` for operator boundaries and the approved safe discovery
commands. Tests use synthetic fixtures and do not inspect live contact data.
