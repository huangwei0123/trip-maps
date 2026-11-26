#!/usr/bin/env python3

import json
import subprocess
from pathlib import Path
import simplekml

PHOTO_EXTENSIONS = {
    ".jpg",
    ".jpeg",
    ".heic",
    ".png",
}

PHOTO_DIR = "./photos"
OUTPUT_KML = "trip_map.kml"


def get_exif(filename):
    result = subprocess.run(
        ["exiftool", "-j", str(filename)],
        capture_output=True,
        text=True,
        check=True
    )

    data = json.loads(result.stdout)
    return data[0]


def main():
    kml = simplekml.Kml()

    count = 0

    for file in Path(PHOTO_DIR).rglob("*"):
        if file.suffix.lower() not in PHOTO_EXTENSIONS:
            continue

        try:
            exif = get_exif(file)

            lat = exif.get("GPSLatitude")
            lon = exif.get("GPSLongitude")

            if lat is None or lon is None:
                continue

            placemark = kml.newpoint(
                name=file.name,
                coords=[(lon, lat)]
            )

            description = [
                f"File: {file.name}"
            ]

            if "DateTimeOriginal" in exif:
                description.append(
                    f"Date: {exif['DateTimeOriginal']}"
                )

            placemark.description = "<br>".join(description)

            count += 1

            print(
                f"Added: {file.name} "
                f"({lat}, {lon})"
            )

        except Exception as e:
            print(f"Error: {file} -> {e}")

    kml.save(OUTPUT_KML)

    print(f"\nCreated {OUTPUT_KML}")
    print(f"Photos mapped: {count}")


if __name__ == "__main__":
    main()
