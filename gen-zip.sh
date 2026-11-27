#!/bin/bash

set -x

rm -f photoonmaps.zip

# zip photoonmaps.zip js/app.js js/auth.js js/kmz.js js/map.js \
#     app.info auth.php icon-512.png icon.svg index.html \
#     manifest.json my_trips.php save_trip.php styles.css sw.js \
#     viewer.html

zip -r photoonmaps.zip index.html viewer.html usage.html styles.css sw.js manifest.json icon-512.png icon.svg *.php js/ lib/

