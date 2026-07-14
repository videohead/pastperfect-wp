# Some lando wp ppwp usage information

lando wp ppwp --info
usage: preface all wp-cli commands with lando to run in lando container
wp ppwp dbf-simulate --source=<path> [--xml-analog=<path>] [--format=<format>] [--report=<path>]
   or: wp ppwp draft-missing-media [--post-type=<post-type>] [--statuses=<statuses>] [--dry-run=<bool>] [--format=<format>]
   or: wp ppwp import-direct --input=<type> --file-path=<path> --media-path=<path> [--increment=<number>] [--media-provider=<provider>] [--media-base-url=<url>] [--dry-run=<bool>] [--format=<format>]
   or: wp ppwp import-simulate --xml=<path> [--format=<format>] [--report=<path>] [--media-provider=<provider>] [--media-source-directory=<path>] [--media-remote-base-url=<url>] [--import-media=<bool>]
   or: wp ppwp media-import-all [--source=<path>] [--limit=<number>] [--dry-run=<bool>] [--format=<format>]
   or: wp ppwp media-index [--source=<path>] [--rebuild=<bool>] [--prune=<bool>] [--hash=<bool>] [--format=<format>]
   or: wp ppwp script <command>

   format = json

# Examples   
lando wp ppwp import-direct --input=xml --file-path=/app/pastperfect/PPSdata-archives.xml --media-path=/app/pastperfect --increment=50
   