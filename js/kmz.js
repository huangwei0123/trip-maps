export function escapeHtml(str) {
  return String(str)
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;");
}

export function resolveKmzFetchUrl(inputUrl) {
  const githubBlobRegex = /^https?:\/\/github\.com\/([^/]+)\/([^/]+)\/blob\/(.+)$/;
  const match = inputUrl.match(githubBlobRegex);
  if (match) {
    return `https://raw.githubusercontent.com/${match[1]}/${match[2]}/${match[3]}`;
  }
  return inputUrl;
}

export function resizeImageToBlob(file, maxWidth = 1000, quality = 0.82) {
  return new Promise((resolve) => {
    const img = new Image();
    const url = URL.createObjectURL(file);
    img.onload = () => {
      let width = img.width;
      let height = img.height;
      if (width > maxWidth) {
        height = Math.round((height * maxWidth) / width);
        width = maxWidth;
      }
      const canvas = document.createElement("canvas");
      canvas.width = width;
      canvas.height = height;
      const ctx = canvas.getContext("2d");
      ctx.drawImage(img, 0, 0, width, height);
      URL.revokeObjectURL(url);
      canvas.toBlob((blob) => resolve(blob), "image/jpeg", quality);
    };
    img.src = url;
  });
}

export async function parseKmzBuffer(arrayBuffer) {
  const zip = await JSZip.loadAsync(arrayBuffer);

  const kmlFileEntry = Object.values(zip.files).find(
    (f) => !f.dir && f.name.toLowerCase().endsWith(".kml")
  );
  if (!kmlFileEntry) throw new Error("No .kml file found inside KMZ.");

  const imageBlobMap = {};
  const rawImageBlobs = {};
  for (const [path, entry] of Object.entries(zip.files)) {
    if (!entry.dir && /\.(jpe?g|png|gif|webp)$/i.test(path)) {
      const rawBlob = await entry.async("blob");
      const ext = path.split(".").pop().toLowerCase();
      const mimeType =
        ext === "png" ? "image/png" : ext === "webp" ? "image/webp" : "image/jpeg";
      const typedBlob = new Blob([rawBlob], { type: mimeType });
      const normalizedPath = path.replace(/^\.?\//, "");
      imageBlobMap[normalizedPath] = URL.createObjectURL(typedBlob);
      rawImageBlobs[normalizedPath] = typedBlob;
    }
  }

  const kmlText = await kmlFileEntry.async("text");
  const parser = new DOMParser();
  const xmlDoc = parser.parseFromString(kmlText, "application/xml");

  const docNameNode = xmlDoc.querySelector("Document > name");
  const docTitle = docNameNode && docNameNode.textContent.trim()
    ? docNameNode.textContent.trim()
    : null;

  const placemarks = Array.from(xmlDoc.querySelectorAll("Placemark"));
  const photos = [];

  for (const pm of placemarks) {
    const pointNode = pm.querySelector("Point > coordinates");
    if (!pointNode) continue;

    const coordParts = pointNode.textContent.trim().split(",");
    if (coordParts.length < 2) continue;

    const lon = parseFloat(coordParts[0]);
    const lat = parseFloat(coordParts[1]);
    const alt = coordParts[2] ? parseFloat(coordParts[2]) : 0;
    if (isNaN(lat) || isNaN(lon)) continue;

    const nameNode = pm.querySelector("name");
    const name = nameNode ? nameNode.textContent.trim() : "Pinned Location";

    const descNode = pm.querySelector("description");
    const descRaw = descNode ? descNode.textContent : "";

    const descDoc = parser.parseFromString(descRaw, "text/html");
    const imgEl = descDoc.querySelector("img");
    let previewUrl = "";
    let matchedBlob = null;

    if (imgEl) {
      const srcAttr = decodeURIComponent(imgEl.getAttribute("src") || "").replace(/^\.?\//, "");
      if (imageBlobMap[srcAttr]) {
        previewUrl = imageBlobMap[srcAttr];
        matchedBlob = rawImageBlobs[srcAttr];
      } else if (/^https?:\/\//i.test(srcAttr)) {
        previewUrl = srcAttr;
      }
    }

    let plainDesc = descDoc.body.textContent.replace(/\s+/g, " ").trim();
    plainDesc = plainDesc
      .replace(/^(Details:\s*)+/i, "")
      .replace(/Coordinates:\s*[-0-9.,\s]+$/i, "")
      .trim();

    photos.push({
      id: crypto.randomUUID(),
      name,
      lat,
      lon,
      alt,
      date: new Date(0),
      dateStr: plainDesc || `${lat.toFixed(5)}, ${lon.toFixed(5)}`,
      previewUrl,
      resizedBlob: matchedBlob,
      enabled: true,
      marker: null,
    });
  }

  return { docTitle, photos };
}

export async function buildKmzBlob(activePhotos, docTitle, connectPath) {
  const zip = new JSZip();
  const imgFolder = zip.folder("images");

  let kml = `<?xml version="1.0" encoding="UTF-8"?>
<kml xmlns="http://www.opengis.net/kml/2.2">
  <Document>
    <name>${escapeHtml(docTitle)}</name>
    <Style id="photoPin">
      <IconStyle>
        <scale>1.2</scale>
        <Icon>
          <href>http://maps.google.com/mapfiles/kml/paddle/red-circle.png</href>
        </Icon>
      </IconStyle>
    </Style>
    <Style id="routeLine">
      <LineStyle>
        <color>ff0055ff</color>
        <width>3</width>
      </LineStyle>
    </Style>
`;

  activePhotos.forEach((photo, idx) => {
    let imgHtml = "";
    if (photo.resizedBlob) {
      const safeFilename = `photo_${idx + 1}.jpg`;
      imgFolder.file(safeFilename, photo.resizedBlob);
      imgHtml = `<img src="images/${safeFilename}" width="420" /><br/>`;
    }

    const descHtml = `${imgHtml}<b>Details:</b> ${escapeHtml(photo.dateStr)}<br/><b>Coordinates:</b> ${photo.lat.toFixed(6)}, ${photo.lon.toFixed(6)}`;

    kml += `    <Placemark>
      <name>${escapeHtml(photo.name)}</name>
      <styleUrl>#photoPin</styleUrl>
      <description><![CDATA[${descHtml}]]></description>
      <Point>
        <coordinates>${photo.lon},${photo.lat},${photo.alt}</coordinates>
      </Point>
    </Placemark>\n`;
  });

  if (connectPath && activePhotos.length > 1) {
    const coordString = activePhotos.map((p) => `${p.lon},${p.lat},${p.alt}`).join(" ");
    kml += `    <Placemark>
      <name>Photo Route</name>
      <styleUrl>#routeLine</styleUrl>
      <LineString>
        <tessellate>1</tessellate>
        <coordinates>${coordString}</coordinates>
      </LineString>
    </Placemark>\n`;
  }

  kml += `  </Document>\n</kml>`;
  zip.file("doc.kml", kml);
  return await zip.generateAsync({ type: "blob" });
}
