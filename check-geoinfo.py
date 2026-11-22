from PIL import Image
from PIL.ExifTags import TAGS, GPSTAGS

def get_exif_data(image_path):
    image = Image.open(image_path)
    exif_data = {}

    if hasattr(image, "_getexif") and image._getexif():
        for tag_id, value in image._getexif().items():
            tag = TAGS.get(tag_id, tag_id)

            if tag == "GPSInfo":
                gps_data = {}
                for gps_tag_id, gps_value in value.items():
                    gps_tag = GPSTAGS.get(gps_tag_id, gps_tag_id)
                    gps_data[gps_tag] = gps_value
                exif_data["GPSInfo"] = gps_data
            else:
                exif_data[tag] = value

    return exif_data

def dms_to_decimal(dms, ref):
    degrees = float(dms[0])
    minutes = float(dms[1])
    seconds = float(dms[2])

    decimal = degrees + minutes / 60 + seconds / 3600

    if ref in ["S", "W"]:
        decimal = -decimal

    return decimal

def extract_gps(image_path):
    exif = get_exif_data(image_path)

    if "GPSInfo" not in exif:
        print("No GPS data found")
        return

    gps = exif["GPSInfo"]

    lat = dms_to_decimal(
        gps["GPSLatitude"],
        gps["GPSLatitudeRef"]
    )

    lon = dms_to_decimal(
        gps["GPSLongitude"],
        gps["GPSLongitudeRef"]
    )

    print(f"Latitude: {lat}")
    print(f"Longitude: {lon}")
    print(f"Google Maps: https://maps.google.com/?q={lat},{lon}")

extract_gps("Swiss-pic001.jpeg")

