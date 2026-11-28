#!/bin/bash

set -x

rm -f photoonmaps.zip

zip -r photoonmaps.zip \
    index.html viewer.html usage.html styles.css sw.js manifest.json \
    icon-512.png icon.svg \
    auth.php verify.php reset.php billing.php stripe_webhook.php \
    save_trip.php my_trips.php stats.php \
    schema.sql migrations/ lib/ js/ \
    -x "config.php" -x "photoonmaps-config.php" -x "users/*" -x "trip/*" -x "stats/*"
