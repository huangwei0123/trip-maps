import { buildKmzBlob, escapeHtml } from "./kmz.js";

function emailToFolderSlug(email) {
  return String(email || "")
    .trim()
    .toLowerCase()
    .replace(/@/g, "_at_")
    .replace(/[^a-z0-9_-]/g, "_")
    .replace(/_+/g, "_")
    .replace(/^_+|_+$/g, "");
}

export function initAccountSystem({ getActivePhotos, isConnectPathChecked, onLoadSavedKmz }) {
  let currentUser = null;
  let currentFolderSlug = null;
  let authMode = "login";

  const userBadge = document.getElementById("userBadge");
  const openAuthModalBtn = document.getElementById("openAuthModalBtn");
  const logoutBtn = document.getElementById("logoutBtn");
  const openMyTripsBtn = document.getElementById("openMyTripsBtn");
  const openSaveModalBtn = document.getElementById("openSaveModalBtn");

  const authModal = document.getElementById("authModal");
  const tabLoginBtn = document.getElementById("tabLoginBtn");
  const tabRegisterBtn = document.getElementById("tabRegisterBtn");
  const authModalHeading = document.getElementById("authModalHeading");
  const authUsernameInput = document.getElementById("authUsernameInput");
  const authPasswordInput = document.getElementById("authPasswordInput");
  const authErrorMsg = document.getElementById("authErrorMsg");
  const closeAuthModalBtn = document.getElementById("closeAuthModalBtn");
  const submitAuthBtn = document.getElementById("submitAuthBtn");

  if (authUsernameInput) {
    authUsernameInput.type = "email";
    authUsernameInput.autocomplete = "email";
    authUsernameInput.placeholder = "you@example.com";
  }

  const saveModal = document.getElementById("saveModal");
  const closeSaveModalBtn = document.getElementById("closeSaveModalBtn");
  const lockedDirDisplay = document.getElementById("lockedDirDisplay");
  const tripNameInput = document.getElementById("tripNameInput");
  const saveKmzOnlyBtn = document.getElementById("saveKmzOnlyBtn");
  const saveImagesOnlyBtn = document.getElementById("saveImagesOnlyBtn");
  const shareResultBox = document.getElementById("shareResultBox");
  const generatedShareUrl = document.getElementById("generatedShareUrl");
  const copySavedUrlBtn = document.getElementById("copySavedUrlBtn");
  const downloadHostedKmzLink = document.getElementById("downloadHostedKmzLink");

  const myTripsModal = document.getElementById("myTripsModal");
  const closeMyTripsBtn = document.getElementById("closeMyTripsBtn");
  const myTripsDirLabel = document.getElementById("myTripsDirLabel");
  const myTripsList = document.getElementById("myTripsList");

  function openModal(modalEl) {
    modalEl.classList.add("open");
    modalEl.style.display = "flex";
  }

  function closeModal(modalEl) {
    modalEl.classList.remove("open");
    modalEl.style.display = "none";
  }

  function updateAuthUI() {
    if (currentUser) {
      const folder = currentFolderSlug || emailToFolderSlug(currentUser);
      userBadge.textContent = currentUser;
      userBadge.title = `Storage directory: /trip/${folder}/`;
      userBadge.style.display = "inline";
      openAuthModalBtn.style.display = "none";
      logoutBtn.style.display = "inline-block";
      openMyTripsBtn.style.display = "inline-block";
      lockedDirDisplay.textContent = `/trip/${folder}/`;
    } else {
      userBadge.style.display = "none";
      openAuthModalBtn.style.display = "inline-block";
      logoutBtn.style.display = "none";
      openMyTripsBtn.style.display = "none";
    }
  }

  function setAuthMode(mode) {
    authMode = mode;
    authErrorMsg.style.display = "none";
    if (mode === "login") {
      tabLoginBtn.classList.remove("secondary");
      tabRegisterBtn.classList.add("secondary");
      authModalHeading.textContent = "Sign In with Your Email";
      submitAuthBtn.textContent = "Login";
    } else {
      tabRegisterBtn.classList.remove("secondary");
      tabLoginBtn.classList.add("secondary");
      authModalHeading.textContent = "Register with Your Email";
      submitAuthBtn.textContent = "Register";
    }
  }

  async function checkSessionStatus() {
    try {
      const res = await fetch("auth.php?action=status");
      const data = await res.json();
      if (data.logged_in) {
        currentUser = data.username;
        currentFolderSlug = data.folder_slug || emailToFolderSlug(data.username);
      } else {
        currentUser = null;
        currentFolderSlug = null;
      }
      updateAuthUI();
    } catch (err) {
      console.warn("Auth status check failed:", err);
    }
  }

  tabLoginBtn.addEventListener("click", () => setAuthMode("login"));
  tabRegisterBtn.addEventListener("click", () => setAuthMode("register"));

  openAuthModalBtn.addEventListener("click", () => {
    setAuthMode("login");
    authModal.classList.add("open"); authModal.style.display = "flex";
  });
  closeAuthModalBtn.addEventListener("click", () => authModal.classList.remove("open"));

  submitAuthBtn.addEventListener("click", async () => {
    authErrorMsg.style.display = "none";
    const emailVal = authUsernameInput.value.trim().toLowerCase();

    if (!emailVal || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(emailVal)) {
      authErrorMsg.textContent = "Please enter a valid email address.";
      authErrorMsg.style.display = "block";
      return;
    }

    submitAuthBtn.disabled = true;

    try {
      const formData = new FormData();
      formData.append("action", authMode);
      formData.append("username", emailVal);
      formData.append("password", authPasswordInput.value);

      const res = await fetch("auth.php", { method: "POST", body: formData });
      const rawText = await res.text();
      let data;
      try {
        data = JSON.parse(rawText);
      } catch {
        throw new Error(`Server returned HTTP ${res.status} (non-JSON).`);
      }

      if (!res.ok || !data.success) {
        throw new Error(data.error || "Authentication failed");
      }

      currentUser = data.username;
      currentFolderSlug = data.folder_slug || emailToFolderSlug(data.username);
      authPasswordInput.value = "";
      authModal.classList.remove("open"); authModal.style.display = "none";
      updateAuthUI();
    } catch (err) {
      authErrorMsg.textContent = err.message;
      authErrorMsg.style.display = "block";
    } finally {
      submitAuthBtn.disabled = false;
    }
  });

  logoutBtn.addEventListener("click", async () => {
    await fetch("auth.php?action=logout");
    currentUser = null;
    currentFolderSlug = null;
    updateAuthUI();
  });

  openSaveModalBtn.addEventListener("click", () => {
    if (!currentUser) {
      setAuthMode("login");
      authModal.classList.add("open"); authModal.style.display = "flex";
      return;
    }
    const folder = currentFolderSlug || emailToFolderSlug(currentUser);
    shareResultBox.style.display = "none";
    lockedDirDisplay.textContent = `/trip/${folder}/`;
    saveModal.classList.add("open"); saveModal.style.display = "flex";
  });

  closeSaveModalBtn.addEventListener("click", () => saveModal.classList.remove("open"));
  copySavedUrlBtn.addEventListener("click", async () => {
    await navigator.clipboard.writeText(generatedShareUrl.value);
    copySavedUrlBtn.textContent = "Copied!";
    setTimeout(() => (copySavedUrlBtn.textContent = "Copy Link"), 2000);
  });

  async function saveToDirectory(saveMode) {
    if (!currentUser) {
      saveModal.classList.remove("open"); saveModal.style.display = "none";
      setAuthMode("login");
      authModal.classList.add("open"); authModal.style.display = "flex";
      return;
    }

    const tripSlug =
      tripNameInput.value.trim().toLowerCase().replace(/[^a-z0-9_-]/g, "-") || `trip-${Date.now()}`;
    const active = getActivePhotos();
    if (!active.length) return;

    saveKmzOnlyBtn.disabled = true;
    saveImagesOnlyBtn.disabled = true;
    const activeBtn = saveMode === "kmz_only" ? saveKmzOnlyBtn : saveImagesOnlyBtn;
    const origLabel = activeBtn.textContent;
    activeBtn.textContent = "Uploading...";

    try {
      const formData = new FormData();
      formData.append("trip_slug", tripSlug);
      formData.append("save_mode", saveMode);

      if (saveMode === "kmz_only") {
        const kmzBlob = await buildKmzBlob(active, tripSlug, isConnectPathChecked());
        formData.append("kmz_file", kmzBlob, `${tripSlug}.kmz`);
      } else {
        let attached = 0;
        active.forEach((photo, idx) => {
          if (photo.resizedBlob) {
            const cleanName = photo.name.replace(/[^a-zA-Z0-9._-]/g, "_") || `photo_${idx + 1}.jpg`;
            formData.append("photos[]", photo.resizedBlob, cleanName);
            attached++;
          }
        });
        if (attached === 0) throw new Error("No image blobs available to upload.");
      }

      const res = await fetch("save_trip.php", { method: "POST", body: formData });
      const rawText = await res.text();
      let data;
      try {
        data = JSON.parse(rawText);
      } catch {
        throw new Error(`Server returned HTTP ${res.status} (non-JSON).`);
      }

      if (!res.ok || !data.success) {
        throw new Error(data.error || "Upload failed");
      }

      const folder = data.folder_slug || currentFolderSlug || emailToFolderSlug(currentUser);
      shareResultBox.style.display = "flex";
      const statusHeader = shareResultBox.querySelector("strong");

      if (saveMode === "kmz_only" && data.kmz_path) {
        const fullShareUrl = `${window.location.origin}/?kmz=${encodeURIComponent(data.kmz_path)}`;
        statusHeader.textContent = "Saved .KMZ! Share this 3D Map Link:";
        generatedShareUrl.value = fullShareUrl;
        downloadHostedKmzLink.href = data.kmz_path;
        downloadHostedKmzLink.style.display = "inline";
        window.history.replaceState({}, "", `/?kmz=${encodeURIComponent(data.kmz_path)}`);
      } else {
        statusHeader.textContent = `Saved ${data.saved_photos.length} image(s) to /trip/${folder}/${tripSlug}/`;
        generatedShareUrl.value = `${window.location.origin}/trip/${folder}/${tripSlug}/`;
        downloadHostedKmzLink.style.display = "none";
      }
    } catch (err) {
      alert(`Save failed: ${err.message}`);
    } finally {
      saveKmzOnlyBtn.disabled = false;
      saveImagesOnlyBtn.disabled = false;
      activeBtn.textContent = origLabel;
    }
  }

  saveKmzOnlyBtn.addEventListener("click", () => saveToDirectory("kmz_only"));
  saveImagesOnlyBtn.addEventListener("click", () => saveToDirectory("images_only"));

  closeMyTripsBtn.addEventListener("click", () => myTripsModal.classList.remove("open"));

  async function fetchAndRenderMyTrips() {
    if (!currentUser) return;
    const folder = currentFolderSlug || emailToFolderSlug(currentUser);
    myTripsDirLabel.textContent = `/trip/${folder}/`;
    myTripsList.innerHTML = `<p style="font-size: 0.85rem; color: #5f6368;">Loading saved trips...</p>`;

    try {
      const res = await fetch("my_trips.php?action=list");
      const rawText = await res.text();
      let data;
      try {
        data = JSON.parse(rawText);
      } catch {
        throw new Error(`Server returned HTTP ${res.status} (non-JSON).`);
      }

      if (!res.ok || !data.success) {
        throw new Error(data.error || "Could not load trips");
      }

      if (!data.trips.length) {
        myTripsList.innerHTML = `<p style="font-size: 0.85rem; color: #5f6368; padding: 12px 0;">No trips saved yet in <code>/trip/${escapeHtml(folder)}/</code>. Upload photos and click <b>Save to Site</b> to store your first trip.</p>`;
        return;
      }

      myTripsList.innerHTML = "";
      data.trips.forEach((trip) => {
        const row = document.createElement("div");
        row.style.cssText =
          "display:flex; justify-content:space-between; align-items:center; gap:10px; padding:10px 12px; border:1px solid #dadce0; border-radius:8px; background:#fafafa;";

        const infoDiv = document.createElement("div");
        infoDiv.style.cssText = "min-width:0; flex:1;";
        infoDiv.innerHTML = `
          <div style="font-weight:600; font-size:0.9rem; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
            ${escapeHtml(trip.slug)}
          </div>
          <div style="font-size:0.76rem; color:#5f6368; margin-top:2px;">
            ${trip.kmz_path ? `${trip.size_kb} KB .KMZ` : "Images only"}
            ${trip.image_count > 0 ? ` • ${trip.image_count} image(s)` : ""}
            • ${escapeHtml(trip.updated_at)}
          </div>
        `;

        const btnGroup = document.createElement("div");
        btnGroup.style.cssText = "display:flex; gap:6px; align-items:center;";

        if (trip.kmz_path) {
          const loadBtn = document.createElement("button");
          loadBtn.type = "button";
          loadBtn.textContent = "Load on Map";
          loadBtn.style.cssText = "padding:6px 10px; font-size:0.8rem;";
          loadBtn.addEventListener("click", () => {
            myTripsModal.classList.remove("open"); myTripsModal.style.display = "none";
            onLoadSavedKmz(trip.kmz_path);
          });
          btnGroup.appendChild(loadBtn);
        }

        const delBtn = document.createElement("button");
        delBtn.type = "button";
        delBtn.className = "secondary";
        delBtn.textContent = "Delete";
        delBtn.style.cssText = "padding:6px 10px; font-size:0.8rem; color:#d93025; border-color:#f5c2c7;";
        delBtn.addEventListener("click", async () => {
          if (!confirm(`Delete "${trip.slug}" permanently from /trip/${folder}/?`)) return;
          delBtn.disabled = true;
          delBtn.textContent = "Deleting...";
          try {
            const formData = new FormData();
            formData.append("action", "delete");
            formData.append("slug", trip.slug);
            const delRes = await fetch("my_trips.php", { method: "POST", body: formData });
            const delData = await delRes.json();
            if (!delRes.ok || !delData.success) {
              throw new Error(delData.error || "Delete failed");
            }
            row.remove();
            if (!myTripsList.children.length) {
              fetchAndRenderMyTrips();
            }
          } catch (err) {
            alert(`Error: ${err.message}`);
            delBtn.disabled = false;
            delBtn.textContent = "Delete";
          }
        });
        btnGroup.appendChild(delBtn);

        row.appendChild(infoDiv);
        row.appendChild(btnGroup);
        myTripsList.appendChild(row);
      });
    } catch (err) {
      myTripsList.innerHTML = `<p style="color:#d93025; font-size:0.84rem;">${escapeHtml(err.message)}</p>`;
    }
  }

  openMyTripsBtn.addEventListener("click", () => {
    if (!currentUser) return;
    myTripsModal.classList.add("open"); myTripsModal.style.display = "flex";
    fetchAndRenderMyTrips();
  });

  return { checkSessionStatus };
}
