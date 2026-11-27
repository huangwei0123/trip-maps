import {
  buildKmzBlob,
  escapeHtml,
  parseKmzBuffer,
  resizeImageToBlob,
  resolveKmzFetchUrl
} from "./kmz.js";
import { createMapController } from "./map.js";
import { initAccountSystem } from "./auth.js?v=9";

const DEFAULT_DEMO_KMZ = "examples/sanitas-20261121.kmz";
const FEATURED_PHOTO_NAME = "IMG_8882.JPEG";

let geotaggedPhotos = [];
let previewWindow = null;
const previewChannel = new BroadcastChannel("photoonmaps_preview_channel");

const pageTitle = document.getElementById("pageTitle");
const kmzUrlInput = document.getElementById("kmzUrlInput");
const loadUrlBtn = document.getElementById("loadUrlBtn");
const loadDemoBtn = document.getElementById("loadDemoBtn");
const resetViewBtn = document.getElementById("resetViewBtn");
const openSaveModalBtn = document.getElementById("openSaveModalBtn");
const dropzone = document.getElementById("dropzone");
const fileInput = document.getElementById("fileInput");
const photoList = document.getElementById("photoList");
const statsBar = document.getElementById("statsBar");
const statusText = document.getElementById("statusText");
const downloadKmzBtn = document.getElementById("downloadKmzBtn");
const clearBtn = document.getElementById("clearBtn");
const removeNoGpsBtn = document.getElementById("removeNoGpsBtn");
const connectPathCheckbox = document.getElementById("connectPathCheckbox");
const terrain3dCheckbox = document.getElementById("terrain3dCheckbox");

function getActivePhotos() {
  return geotaggedPhotos.filter((p) => p.enabled !== false);
}

function highlightSidebarItem(photoId) {
  document.querySelectorAll(".photo-item.active").forEach((el) => {
    el.classList.remove("active");
  });

  if (!photoId) return;

  const target = photoList.querySelector(`.photo-item[data-photo-id="${CSS.escape(photoId)}"]`);
  if (target) {
    target.classList.add("active");
    target.scrollIntoView({ behavior: "smooth", block: "nearest" });
  }
}

function buildPreviewPayload(selectedItem) {
  const visibleItems = getActivePhotos().filter((p) => p.previewUrl);
  const items = visibleItems.map((p) => ({
    id: p.id,
    url: p.previewUrl,
    name: p.name,
    meta: `Coordinates: ${p.lat.toFixed(6)}, ${p.lon.toFixed(6)}${p.dateStr ? ` • ${p.dateStr}` : ""}`,
  }));

  let idx = visibleItems.findIndex((p) => p.id === selectedItem.id);
  if (idx === -1) {
    items.unshift({
      id: selectedItem.id,
      url: selectedItem.previewUrl,
      name: selectedItem.name,
      meta: selectedItem.dateStr || "No GPS Metadata",
    });
    idx = 0;
  }

  return { items, index: idx };
}

function openInSinglePreviewTab(item) {
  if (!item.previewUrl) return;
  const payload = buildPreviewPayload(item);

  if (previewWindow && !previewWindow.closed) {
    previewChannel.postMessage(payload);
    previewWindow.focus();
    return;
  }

  const viewerUrl = `viewer.html#${encodeURIComponent(JSON.stringify(payload))}`;
  previewWindow = window.open(
    viewerUrl,
    "photoonmaps_view",
    "width=1100,height=820,resizable=yes,scrollbars=yes"
  );
  if (previewWindow) previewWindow.focus();
}

const mapCtrl = createMapController({
  onPhotoPreview: openInSinglePreviewTab,
  onPinSelect: (photo) => {
    highlightSidebarItem(photo ? photo.id : null);
  }
});

mapCtrl.onLoad(() => {
  if (geotaggedPhotos.length > 0) {
    mapCtrl.renderOverlays(getActivePhotos(), {
      connectPath: connectPathCheckbox.checked,
      use3d: terrain3dCheckbox.checked,
      autoOpenPhotoName: FEATURED_PHOTO_NAME
    });
  }
});

previewChannel.onmessage = (event) => {
  if (!event.data || event.data.type !== "sync_map_pin") return;
  const targetPhoto = geotaggedPhotos.find((p) => p.id === event.data.id);
  if (targetPhoto) {
    highlightSidebarItem(targetPhoto.id);
    mapCtrl.flyToPhoto(targetPhoto, terrain3dCheckbox.checked, 1200);
  }
};

function updateNoGpsButton() {
  const noGpsCount = document.querySelectorAll(".photo-item.no-gps").length;
  removeNoGpsBtn.style.display = noGpsCount > 0 ? "inline-block" : "none";
}

function updateUI() {
  const count = getActivePhotos().length;
  statsBar.firstElementChild.textContent = `${count} geotagged pin${count === 1 ? "" : "s"}`;
  downloadKmzBtn.disabled = count === 0;
  openSaveModalBtn.disabled = count === 0;
  resetViewBtn.disabled = count === 0;
  clearBtn.disabled = photoList.children.length === 0;
}

function refreshOverlays(autoOpenPhotoName = null) {
  highlightSidebarItem(null);
  mapCtrl.renderOverlays(getActivePhotos(), {
    connectPath: connectPathCheckbox.checked,
    use3d: terrain3dCheckbox.checked,
    autoOpenPhotoName
  });
}

function resetWorkspace(clearQueryParam = false) {
  geotaggedPhotos.forEach((p) => {
    if (p.previewUrl && p.previewUrl.startsWith("blob:")) {
      URL.revokeObjectURL(p.previewUrl);
    }
  });
  geotaggedPhotos = [];
  photoList.innerHTML = "";
  mapCtrl.clearMarkers();

  pageTitle.textContent = "PhotoOnMaps.com";
  updateNoGpsButton();

  if (clearQueryParam && window.location.protocol.startsWith("http")) {
    window.history.replaceState({}, "", window.location.pathname);
  }
  updateUI();
}

function appendSidebarItem(item, hasGps) {
  const div = document.createElement("div");
  div.className = `photo-item ${hasGps ? "" : "no-gps"}`;
  if (item.id) {
    div.dataset.photoId = item.id;
  }

  const thumbHtml = item.previewUrl
    ? `<img src="${item.previewUrl}" alt="${escapeHtml(item.name)}" class="thumbnail-pic" />`
    : `<div class="thumbnail-placeholder">📍</div>`;

  div.innerHTML = `
    ${hasGps ? `<input type="checkbox" class="pin-toggle" checked title="Include in map & KMZ" />` : ""}
    ${thumbHtml}
    <div class="photo-meta">
      <div class="photo-name" title="${escapeHtml(item.name)}">${escapeHtml(item.name)}</div>
      <div class="photo-coords">
        ${hasGps ? `${item.lat.toFixed(5)}, ${item.lon.toFixed(5)}` : "No GPS metadata found"}
      </div>
    </div>
    <div class="photo-actions">
      ${item.previewUrl ? `<button class="open-tab-btn" title="Open full-size in preview window">Open</button>` : ""}
      <button class="remove-item-btn" title="Remove from list">&times;</button>
    </div>
  `;

  const openBtn = div.querySelector(".open-tab-btn");
  if (openBtn) {
    openBtn.addEventListener("click", (e) => {
      e.stopPropagation();
      openInSinglePreviewTab(item);
    });
  }

  const imgThumb = div.querySelector(".thumbnail-pic");
  if (imgThumb && item.previewUrl) {
    imgThumb.addEventListener("click", (e) => {
      e.stopPropagation();
      openInSinglePreviewTab(item);
    });
    imgThumb.addEventListener("contextmenu", (e) => {
      e.preventDefault();
      e.stopPropagation();
      openInSinglePreviewTab(item);
    });
  }

  div.querySelector(".remove-item-btn").addEventListener("click", (e) => {
    e.stopPropagation();
    if (item.previewUrl && item.previewUrl.startsWith("blob:")) {
      URL.revokeObjectURL(item.previewUrl);
    }
    if (hasGps) {
      geotaggedPhotos = geotaggedPhotos.filter((p) => p.id !== item.id);
      refreshOverlays();
    }
    div.remove();
    updateNoGpsButton();
    updateUI();
  });

  if (hasGps) {
    const checkbox = div.querySelector(".pin-toggle");
    checkbox.addEventListener("click", (e) => e.stopPropagation());
    checkbox.addEventListener("change", () => {
      item.enabled = checkbox.checked;
      div.style.opacity = item.enabled ? "1" : "0.5";
      refreshOverlays();
      updateUI();
    });

    div.addEventListener("click", () => {
      highlightSidebarItem(item.id);
      mapCtrl.flyToPhoto(item, terrain3dCheckbox.checked, 1400);
    });
  }

  photoList.appendChild(div);
  updateNoGpsButton();
}

async function loadKmzFromUrl(rawInput, updateBrowserUrl = false, autoOpenPhotoName = null) {
  resetWorkspace(false);
  statusText.textContent = "Loading trip...";
  loadUrlBtn.disabled = true;

  try {
    const fetchUrl = resolveKmzFetchUrl(rawInput);
    const response = await fetch(fetchUrl);
    if (!response.ok) {
      throw new Error(`HTTP ${response.status} (${response.statusText})`);
    }
    const arrayBuffer = await response.arrayBuffer();
    statusText.textContent = "Unpacking KMZ...";
    const { docTitle, photos } = await parseKmzBuffer(arrayBuffer);

    if (docTitle) {
      pageTitle.textContent = `PhotoOnMaps — ${docTitle}`;
    }

    geotaggedPhotos = photos;
    photos.forEach((p) => appendSidebarItem(p, true));

    refreshOverlays(autoOpenPhotoName);
    updateUI();
    statusText.textContent = "Loaded in 3D";

    if (updateBrowserUrl && window.location.protocol.startsWith("http")) {
      const newUrl = `${window.location.pathname}?kmz=${encodeURIComponent(rawInput)}`;
      window.history.replaceState({}, "", newUrl);
    }
  } catch (err) {
    console.error("Failed to load KMZ:", err);
    statusText.textContent = "Ready";
  } finally {
    loadUrlBtn.disabled = false;
  }
}

async function handleFiles(fileList) {
  const files = Array.from(fileList);
  if (!files.length) return;

  if (kmzUrlInput.value.trim() === DEFAULT_DEMO_KMZ && geotaggedPhotos.length > 0) {
    resetWorkspace(true);
    kmzUrlInput.value = "";
  }

  const kmzFile = files.find((f) => f.name.toLowerCase().endsWith(".kmz"));
  if (kmzFile) {
    resetWorkspace(true);
    statusText.textContent = "Unpacking KMZ...";
    const buf = await kmzFile.arrayBuffer();
    const { docTitle, photos } = await parseKmzBuffer(buf);
    if (docTitle) pageTitle.textContent = `PhotoOnMaps — ${docTitle}`;
    geotaggedPhotos = photos;
    photos.forEach((p) => appendSidebarItem(p, true));
    refreshOverlays();
    updateUI();
    statusText.textContent = "Loaded in 3D";
    return;
  }

  statusText.textContent = `Processing ${files.length} file(s)...`;

  for (const file of files) {
    try {
      const exifData = await exifr.parse(file, true);
      const previewUrl = URL.createObjectURL(file);

      if (exifData && typeof exifData.latitude === "number" && typeof exifData.longitude === "number") {
        const rawDate = exifData.DateTimeOriginal || exifData.CreateDate || new Date(file.lastModified);
        const dateObj = rawDate instanceof Date ? rawDate : new Date(rawDate);
        const resizedBlob = await resizeImageToBlob(file);

        const photoEntry = {
          id: crypto.randomUUID(),
          name: file.name,
          lat: exifData.latitude,
          lon: exifData.longitude,
          alt: typeof exifData.GPSAltitude === "number" ? exifData.GPSAltitude : 0,
          date: dateObj,
          dateStr: isNaN(dateObj) ? "Unknown date" : dateObj.toLocaleString(),
          previewUrl,
          resizedBlob,
          enabled: true,
          marker: null,
        };

        geotaggedPhotos.push(photoEntry);
        appendSidebarItem(photoEntry, true);
      } else {
        appendSidebarItem({ name: file.name, previewUrl }, false);
      }
    } catch (err) {
      console.error("Failed to read EXIF for", file.name, err);
      appendSidebarItem({ name: file.name, previewUrl: "" }, false);
    }
  }

  geotaggedPhotos.sort((a, b) => a.date - b.date);
  refreshOverlays();
  updateUI();
  statusText.textContent = "Ready";
}

// Event Listeners
dropzone.addEventListener("click", () => fileInput.click());
dropzone.addEventListener("dragover", (e) => {
  e.preventDefault();
  dropzone.classList.add("dragover");
});
dropzone.addEventListener("dragleave", () => dropzone.classList.remove("dragover"));
dropzone.addEventListener("drop", (e) => {
  e.preventDefault();
  dropzone.classList.remove("dragover");
  handleFiles(e.dataTransfer.files);
});
fileInput.addEventListener("change", (e) => {
  handleFiles(e.target.files);
  fileInput.value = "";
});

connectPathCheckbox.addEventListener("change", () => {
  mapCtrl.updateRouteLine(getActivePhotos(), connectPathCheckbox.checked);
});

terrain3dCheckbox.addEventListener("change", () => {
  mapCtrl.setTerrain3D(terrain3dCheckbox.checked);
});

resetViewBtn.addEventListener("click", () => {
  mapCtrl.fitToPins(getActivePhotos(), terrain3dCheckbox.checked, true);
});

loadDemoBtn.addEventListener("click", () => {
  kmzUrlInput.value = DEFAULT_DEMO_KMZ;
  loadKmzFromUrl(DEFAULT_DEMO_KMZ, false, FEATURED_PHOTO_NAME);
});

loadUrlBtn.addEventListener("click", () => {
  const val = kmzUrlInput.value.trim();
  if (val) loadKmzFromUrl(val, true);
});

removeNoGpsBtn.addEventListener("click", () => {
  document.querySelectorAll(".photo-item.no-gps").forEach((el) => el.remove());
  updateNoGpsButton();
  updateUI();
});

clearBtn.addEventListener("click", () => resetWorkspace(true));

downloadKmzBtn.addEventListener("click", async () => {
  const active = getActivePhotos();
  if (!active.length) return;

  downloadKmzBtn.disabled = true;
  downloadKmzBtn.textContent = "Building KMZ...";

  const kmzBlob = await buildKmzBlob(active, pageTitle.textContent, connectPathCheckbox.checked);
  const downloadUrl = URL.createObjectURL(kmzBlob);
  const a = document.createElement("a");
  a.href = downloadUrl;
  a.download = "photoonmaps_trip.kmz";
  document.body.appendChild(a);
  a.click();
  a.remove();
  URL.revokeObjectURL(downloadUrl);

  downloadKmzBtn.disabled = false;
  downloadKmzBtn.textContent = "Download .KMZ";
});

const account = initAccountSystem({
  getActivePhotos,
  isConnectPathChecked: () => connectPathCheckbox.checked,
  onLoadSavedKmz: (kmzPath) => {
    kmzUrlInput.value = kmzPath;
    loadKmzFromUrl(kmzPath, true);
  }
});

window.addEventListener("DOMContentLoaded", () => {
  account.checkSessionStatus();
  const params = new URLSearchParams(window.location.search);
  const kmzParam = params.get("kmz");
  if (kmzParam) {
    kmzUrlInput.value = kmzParam;
    loadKmzFromUrl(kmzParam, false);
  } else {
    kmzUrlInput.value = DEFAULT_DEMO_KMZ;
    loadKmzFromUrl(DEFAULT_DEMO_KMZ, false, FEATURED_PHOTO_NAME);
  }
});

if ("serviceWorker" in navigator) {
  window.addEventListener("load", () => {
    navigator.serviceWorker.register("sw.js").catch((err) => {
      console.warn("Service worker registration skipped:", err);
    });
  });
}
