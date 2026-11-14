#!/bin/bash
# Builds dist/noncepay-whmcs.zip: the modules/ tree to unzip into the WHMCS root, with the license inside the module folder.
set -euo pipefail
cd "$(dirname "$0")/.."
rm -rf dist && mkdir -p dist/noncepay-whmcs
rsync -a --exclude='.DS_Store' modules dist/noncepay-whmcs/
cp LICENSE dist/noncepay-whmcs/modules/gateways/noncepay/LICENSE
(cd dist/noncepay-whmcs && zip -qr ../noncepay-whmcs.zip modules)
rm -rf dist/noncepay-whmcs
echo "dist/noncepay-whmcs.zip"
