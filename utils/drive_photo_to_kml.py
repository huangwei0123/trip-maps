import html
import io
import os
import re
from google.auth.transport.requests import Request
from google.oauth2.credentials import Credentials
from google_auth_oauthlib.flow import InstalledAppFlow
from googleapiclient.discovery import build
from googleapiclient.http import MediaIoBaseDownload
from PIL import ExifTags, Image

# Read-only access to Google Drive
SCOPES = ["https://www.googleapis.com/auth/drive.readonly"]

# Extract folder ID from the URL or paste it directly
FOLDER_URL = "https://drive.google.com/drive/folders/1_MEI1zsBnK2ZWSgQV6LknWlrtgZ6N7Jb"
OUTPUT_KML = "google_earth_photos.kml"


def get_folder_id(url_or_id: str) -> str:
    match = re.search(r"folders/([a-zA-Z0-9_-]+)", url_or_id)
    return match.group(1) if match else url_or_id


def authenticate_drive():
    creds = None
    if os.path.exists("token.json"):
        creds = Credentials.from_authorized_user_file("token.json", SCOPES)
    if not creds or not creds.valid:
        if creds and creds.expired and creds.refresh_token:
            creds.refresh(Request())
        else:
            flow = InstalledAppFlow.from_client_secrets_file(
                "credentials.json", SCOPES
            )
            creds = flow.run_local_server(port=0)
        with open("token.json", "w") as token:
            token.write(creds.to_json())
    return build("drive", "v3", credentials=creds)


def dms_to_decimal(dms, ref) -> float:
    degrees = float(dms[0])
    minutes = float(dms[1])
    seconds = float(dms[2])
    decimal = degrees + (minutes / 60.0) + (seconds / 3600.0)
    if ref in ["S", "W"]:
        decimal = -decimal
    return decimal


def extract_gps_and_date(image_bytes: bytes):
    try:
        img = Image.open(io.BytesIO(image_bytes))
        exif_raw = img._getexif()
        if not exif_raw:
            return None

        exif = {ExifTags.TAGS.get(k, k): v for k, v in exif_raw.items()}
        gps_info_raw = exif.get("GPSInfo")
        if not gps_info_raw:
            return None

        gps_info = {
            ExifTags.GPSTAGS.get(k, k): v for k, v in gps_info_raw.items()
        }

        if (
            "GPSLatitude" not in gps_info
            or "GPSLatitudeRef" not in gps_info
            or "GPSLongitude" not in gps_info
            or "GPSLongitudeRef" not in gps_info
        ):
            return None

        lat = dms_to_decimal(
            gps_info["GPSLatitude"], gps_info["GPSLatitudeRef"]
        )
        lon = dms_to_decimal(
            gps_info["GPSLongitude"], gps_info["GPSLongitudeRef"]
        )
        alt = float(gps_info.get("GPSAltitude", 0.0))
        date_taken = exif.get("DateTimeOriginal", "Unknown date")

        return {"lat": lat, "lon": lon, "alt": alt, "date": date_taken}
    except Exception as e:
        print(f"  [!] Error reading EXIF: {e}")
        return None


def main():
    service = authenticate_drive()
    folder_id = get_folder_id(FOLDER_URL)

    # List all image files in the folder
    query = (
        f"'{folder_id}' in parents and mimeType contains 'image/' and trashed ="
        " false"
    )
    results = (
        service.files()
        .list(
            q=query,
            pageSize=100,
            fields="files(id, name, webViewLink, thumbnailLink)",
        )
        .execute()
    )
    files = results.get("files", [])

    if not files:
        print("No images found in the specified folder.")
        return

    print(f"Found {len(files)} images. Checking GPS metadata...\n")
    placemarks = []

    for f in files:
        file_id = f["id"]
        name = f["name"]
        view_link = f.get("webViewLink", "")
        # Request a larger thumbnail (width 800px) if available
        thumb_link = f.get("thumbnailLink", "").replace("=s220", "=s800")

        request = service.files().get_media(fileId=file_id)
        fh = io.BytesIO()
        downloader = MediaIoBaseDownload(fh, request)
        done = False
        while not done:
            _, done = downloader.next_chunk()

        gps_data = extract_gps_and_date(fh.getvalue())
        if gps_data:
            print(
                f"  [OK] {name}: ({gps_data['lat']:.6f}, {gps_data['lon']:.6f})"
            )
            placemarks.append({
                "name": name,
                "lat": gps_data["lat"],
                "lon": gps_data["lon"],
                "alt": gps_data["alt"],
                "date": gps_data["date"],
                "view_link": view_link,
                "thumb_link": thumb_link,
            })
        else:
            print(f"  [--] {name}: No GPS data found, skipping.")

    if not placemarks:
        print("\nNo geotagged images found. KML file was not created.")
        return

    # Build KML content
    kml_lines = [
        '<?xml version="1.0" encoding="UTF-8"?>',
        '<kml xmlns="http://www.opengis.net/kml/2.2">',
        "  <Document>",
        "    <name>Google Drive Photos</name>",
        '    <Style id="photoPin">',
        "      <IconStyle>",
        "        <scale>1.2</scale>",
        "        <Icon>",
        "          <href>http://maps.google.com/mapfiles/kml/paddle/red-circle.png</href>",
        "        </Icon>",
        "      </IconStyle>",
        "    </Style>",
    ]

    for pm in placemarks:
        safe_name = html.escape(pm["name"])
        img_html = (
            f'<img src="{pm["thumb_link"]}" width="400" /><br/>'
            if pm["thumb_link"]
            else ""
        )
        desc_html = (
            f"{img_html}"
            f"<b>Date Taken:</b> {html.escape(pm['date'])}<br/>"
            f'<a href="{pm["view_link"]}">Open Full Image in Google Drive</a>'
        )

        kml_lines.extend([
            "    <Placemark>",
            f"      <name>{safe_name}</name>",
            "      <styleUrl>#photoPin</styleUrl>",
            f"      <description><![CDATA[{desc_html}]]></description>",
            "      <Point>",
            f"        <coordinates>{pm['lon']},{pm['lat']},{pm['alt']}</coordinates>",
            "      </Point>",
            "    </Placemark>",
        ])

    kml_lines.extend(["  </Document>", "</kml>"])

    with open(OUTPUT_KML, "w", encoding="utf-8") as out:
        out.write("\n".join(kml_lines))

    print(
        f"\nSuccess! Saved {len(placemarks)} pinned photos to '{OUTPUT_KML}'."
    )


if __name__ == "__main__":
    main()
