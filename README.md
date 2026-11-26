# AMS AI Bridge

The Bridge provides controlled access to Elementor working drafts. Its REST endpoints and WordPress permissions are documented in the [Bridge pilot notes](ams-ai-bridge/README.md).

## Releasing

1. Bump the header and version constant in `ams-ai-bridge/ams-ai-bridge.php`.
2. Run `bash release.sh "What changed"` with PHP and Python 3 installed. This lints the plugin and builds the release ZIP and manifest.
3. Commit the source, ZIP and `update.json`, then push to the public repository's `main` branch. Keep repository write access limited because it controls the code WordPress installs.

The first release with the updater requires one manual replacement of the existing plugin. For the next release, choose **Dashboard → Updates → Check again**, verify **AMS AI Bridge** is offered, select **Update now**, and confirm the installed version. The Bridge checks only packages from this repository and verifies the ZIP against its manifest SHA-256. Leave the two older roofing working drafts unapplied. Drafts made before the expanded live-page check require a fresh working copy.
