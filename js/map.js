import { escapeHtml } from "./kmz.js";

export function createMapController({ onPhotoPreview, onPinSelect }) {
  let mapReady = false;
  let activeMarkers = [];

  const map = new maplibregl.Map({
    container: "map",
    center: [-105.31, 40.03],
    zoom: 12,
    pitch: 58,
    bearing: -20,
    maxPitch: 85,
    style: {
      version: 8,
      sources: {
        satellite: {
          type: "raster",
          tiles: [
            "https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}"
          ],
          tileSize: 256,
          attribution: "Imagery &copy; Esri, Maxar, Earthstar Geographics"
        },
        terrainDem: {
          type: "raster-dem",
          tiles: [
            "https://s3.amazonaws.com/elevation-tiles-prod/terrarium/{z}/{x}/{y}.png"
          ],
          encoding: "terrarium",
          tileSize: 256,
          maxzoom: 15
        }
      },
      layers: [{ id: "satellite-layer", type: "raster", source: "satellite" }],
      terrain: { source: "terrainDem", exaggeration: 1.25 },
      sky: {
        "sky-color": "#7ec0ee",
        "sky-horizon-blend": 0.5,
        "horizon-color": "#e8f1f8",
        "horizon-fog-blend": 0.8,
        "fog-color": "#dce6f0",
        "fog-ground-blend": 0.4
      }
    }
  });

  map.addControl(new maplibregl.NavigationControl({ visualizePitch: true }), "top-right");
  map.addControl(new maplibregl.ScaleControl({ unit: "imperial" }), "bottom-right");

  function clearMarkers() {
    activeMarkers.forEach((m) => m.remove());
    activeMarkers = [];
    if (mapReady && map.getSource("route-source")) {
      map.getSource("route-source").setData({ type: "FeatureCollection", features: [] });
    }
  }

  function updateRouteLine(activePhotos, connectPath) {
    if (!mapReady || !map.getSource("route-source")) return;

    if (connectPath && activePhotos.length > 1) {
      const lineCoords = activePhotos.map((p) => [p.lon, p.lat]);
      map.getSource("route-source").setData({
        type: "FeatureCollection",
        features: [
          {
            type: "Feature",
            geometry: { type: "LineString", coordinates: lineCoords }
          }
        ]
      });
    } else {
      map.getSource("route-source").setData({ type: "FeatureCollection", features: [] });
    }
  }

  function fitToPins(activePhotos, use3d, animate = true) {
    if (!activePhotos.length) return;
    const bounds = new maplibregl.LngLatBounds();
    activePhotos.forEach((p) => bounds.extend([p.lon, p.lat]));

    map.fitBounds(bounds, {
      padding: { top: 90, bottom: 80, left: 80, right: 80 },
      maxZoom: 15.2,
      pitch: use3d ? 58 : 0,
      bearing: use3d ? -20 : 0,
      duration: animate ? 1500 : 0
    });
  }

  function flyToPhoto(photo, use3d, duration = 1400) {
    if (!photo || photo.enabled === false) return;
    activeMarkers.forEach((m) => {
      if (m !== photo.marker && m.getPopup().isOpen()) m.togglePopup();
    });

    map.flyTo({
      center: [photo.lon, photo.lat],
      zoom: 15.8,
      pitch: use3d ? 62 : 0,
      duration,
      essential: true
    });

    if (photo.marker && !photo.marker.getPopup().isOpen()) {
      photo.marker.togglePopup();
    } else if (onPinSelect) {
      onPinSelect(photo);
    }
  }

  function renderOverlays(activePhotos, { connectPath, use3d, autoOpenPhotoName = null }) {
    clearMarkers();
    if (!activePhotos.length) return;

    let featuredEntry = null;

    activePhotos.forEach((photo) => {
      const el = document.createElement("div");
      el.className = "photo-pin";
      el.title = photo.name;

      const popupEl = document.createElement("div");
      popupEl.className = "popup-card";
      popupEl.innerHTML = `
        ${
          photo.previewUrl
            ? `<img src="${photo.previewUrl}" alt="${escapeHtml(photo.name)}" style="cursor:pointer;" title="Click to open full-size in preview window" />`
            : ""
        }
        <strong>${escapeHtml(photo.name)}</strong>
        <small>${escapeHtml(photo.dateStr)}</small>
      `;

      const popupImg = popupEl.querySelector("img");
      if (popupImg && onPhotoPreview) {
        popupImg.addEventListener("click", (e) => {
          e.stopPropagation();
          onPhotoPreview(photo);
        });
        popupImg.addEventListener("contextmenu", (e) => {
          e.preventDefault();
          e.stopPropagation();
          onPhotoPreview(photo);
        });
      }

      const popup = new maplibregl.Popup({ offset: 22, maxWidth: "275px" }).setDOMContent(popupEl);

      popup.on("open", () => {
        if (onPinSelect) onPinSelect(photo);
      });

      popup.on("close", () => {
        const anyStillOpen = activeMarkers.some((m) => m.getPopup() && m.getPopup().isOpen());
        if (!anyStillOpen && onPinSelect) {
          onPinSelect(null);
        }
      });

      const marker = new maplibregl.Marker({ element: el, anchor: "bottom" })
        .setLngLat([photo.lon, photo.lat])
        .setPopup(popup)
        .addTo(map);

      photo.marker = marker;
      activeMarkers.push(marker);

      if (autoOpenPhotoName && photo.name.toLowerCase() === autoOpenPhotoName.toLowerCase()) {
        featuredEntry = photo;
      }
    });

    updateRouteLine(activePhotos, connectPath);
    fitToPins(activePhotos, use3d, true);

    if (featuredEntry && featuredEntry.marker) {
      setTimeout(() => {
        if (!featuredEntry.marker.getPopup().isOpen()) {
          featuredEntry.marker.togglePopup();
        }
      }, 1600);
    }
  }

  function setTerrain3D(enabled) {
    if (enabled) {
      map.setTerrain({ source: "terrainDem", exaggeration: 1.25 });
      map.easeTo({ pitch: 58, duration: 800 });
    } else {
      map.setTerrain(null);
      map.easeTo({ pitch: 0, bearing: 0, duration: 800 });
    }
  }

  function onLoad(callback) {
    map.on("load", () => {
      mapReady = true;
      map.addSource("route-source", {
        type: "geojson",
        data: { type: "FeatureCollection", features: [] }
      });
      map.addLayer({
        id: "route-line-casing",
        type: "line",
        source: "route-source",
        layout: { "line-join": "round", "line-cap": "round" },
        paint: { "line-color": "#ffffff", "line-width": 5, "line-opacity": 0.75 }
      });
      map.addLayer({
        id: "route-line",
        type: "line",
        source: "route-source",
        layout: { "line-join": "round", "line-cap": "round" },
        paint: { "line-color": "#ff3b30", "line-width": 3, "line-dasharray": [2, 1.5] }
      });
      if (callback) callback();
    });
  }

  return {
    onLoad,
    clearMarkers,
    updateRouteLine,
    fitToPins,
    flyToPhoto,
    renderOverlays,
    setTerrain3D
  };
}
