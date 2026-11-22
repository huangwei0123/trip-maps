import subprocess
import json

filename = "Swiss-pic001.jpeg"

result = subprocess.run(
    ["exiftool", "-j", filename],
    capture_output=True,
    text=True
)

data = json.loads(result.stdout)[0]

print("GPS Latitude:", data.get("GPSLatitude"))
print("GPS Longitude:", data.get("GPSLongitude"))
