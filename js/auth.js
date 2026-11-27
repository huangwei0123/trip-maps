import { buildKmzBlob, escapeHtml } from "./kmz.js";

export function initAccountSystem({ getActivePhotos, isConnectPathChecked, onLoadSavedKmz }) {
  let currentUser = null;
  let authMode = "login"; // 'login' | 'register' | 'reset'

  const userBadge = document.getElementById("userBadge");
  const openAuthModalBtn = document.getElementById("openAuthModalBtn");
  const openMyTripsBtn = document.getElementById("openMyTripsBtn");
  const openUsageBtn = document.getElementById("openUsageBtn");
  const openResetPwdBtn = document.getElementById("openResetPwdBtn");
  const logoutBtn = document.getElementById("logoutBtn");
  const openSaveModalBtn = document.getElementById("openSaveModalBtn");

  const authModal = document.getElementById("authModal");
  const tabLoginBtn = document.getElementById("tabLoginBtn");
  const tabRegisterBtn = document.getElementById("tabRegisterBtn");
  const tabResetBtn = document.getElementById("tabResetBtn");
  const authModalHeading = document.getElementById("authModalHeading");
  const authModalSubtext = document.getElementById("authModalSubtext");
  const authUsernameGroup = document.getElementById("authUsernameGroup");
  const authUsernameInput = document.getElementById("authUsernameInput");
  const recoveryKeyGroup = document.getElementById("recoveryKeyGroup");
  const authRecoveryKeyInput = document.getElementById("authRecoveryKeyInput");
  const authPasswordLabel = document.getElementById("authPasswordLabel");
  const authPasswordInput = document.getElementById("authPasswordInput");

  const authErrorBox = document.getElementById("authErrorBox");
  const authErrorMsg = document.getElementById("authErrorMsg");
  const inlineResetTriggerBtn = document.getElementById("inlineResetTriggerBtn");

  const recoveryKeyBox = document.getElementById("recoveryKeyBox");
  const recoveryKeyTitle = document.getElementById("recoveryKeyTitle");
  const recoveryKeyValue = document.getElementById("recoveryKeyValue");
  const copyRecoveryKeyBtn = document.getElementById("copyRecoveryKeyBtn");

  const closeAuthModalBtn = document.getElementById("closeAuthModalBtn");
  const submitAuthBtn = document.getElementById("submitAuthBtn");

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

  function openModal(el) {
    el.classList.add("open");
    el.style.display = "flex";
  }

  function closeModal(el) {
    el.classList.remove("open");
    el.style.display = "none";
  }

  function updateAuthUI() {
    if (currentUser) {
      userBadge.textContent = `/trip/${currentUser}/`;
      userBadge.style.display = "inline";
      openAuthModalBtn.style.display = "none";
      openMyTripsBtn.style.display = "inline-block";
      if (openUsageBtn) openUsageBtn.style.display = "inline-block";
      openResetPwdBtn.style.display = "inline-block";
      logoutBtn.style.display = "inline-block";
      lockedDirDisplay.textContent = `/trip/${currentUser}/`;
    } else {
      userBadge.style.display = "none";
      openAuthModalBtn.style.display = "inline-block";
      openMyTripsBtn.style.display = "none";
      if (openUsageBtn) openUsageBtn.style.display = "none";
      openResetPwdBtn.style.display = "none";
      logoutBtn.style.display = "none";
    }
  }

  function clearAuthFeedback() {
    authErrorBox.style.display = "none";
    inlineResetTriggerBtn.style.display = "none";
    recoveryKeyBox.style.display = "none";
  }

  function setAuthMode(mode) {
    authMode = mode;
    clearAuthFeedback();
    submitAuthBtn.style.display = "inline-block";

    tabLoginBtn.classList.toggle("secondary", mode !== "login");
    tabRegisterBtn.classList.toggle("secondary", mode !== "register");
    tabResetBtn.classList.toggle("secondary", mode !== "reset");

    if (mode === "login") {
      authModalHeading.textContent = "Sign In to Your Account";
      authModalSubtext.innerHTML = 'Your saved .KMZ files and photos are stored in your private <code>/trip/&lt;username&gt;/</code> directory.';
      authUsernameGroup.style.display = "flex";
      recoveryKeyGroup.style.display = "none";
      authPasswordLabel.textContent = "Password";
      authPasswordInput.placeholder = "Enter your password";
      authPasswordInput.autocomplete = "current-password";
      submitAuthBtn.textContent = "Login";
    } else if (mode === "register") {
      authModalHeading.textContent = "Create a New Account";
      authModalSubtext.innerHTML = 'Creates your personal <code>/trip/&lt;username&gt;/</code> folder and generates a backup recovery key.';
      authUsernameGroup.style.display = "flex";
      recoveryKeyGroup.style.display = "none";
      authPasswordLabel.textContent = "Create Password (min 6 characters)";
      authPasswordInput.placeholder = "Choose a password";
      authPasswordInput.autocomplete = "new-password";
      submitAuthBtn.textContent = "Register";
    } else if (mode === "reset") {
      authModalHeading.textContent = currentUser
        ? `Reset Password for ${currentUser}`
        : "Reset Forgotten Password";
      if (currentUser) {
        authModalSubtext.textContent = "You are currently signed in. Enter a new password below.";
        authUsernameInput.value = currentUser;
        authUsernameGroup.style.display = "none";
        recoveryKeyGroup.style.display = "none";
      } else {
        authModalSubtext.textContent = "Enter your username, your 8-character recovery key, and a new password.";
        authUsernameGroup.style.display = "flex";
        recoveryKeyGroup.style.display = "flex";
      }
      authPasswordLabel.textContent = "New Password (min 6 characters)";
      authPasswordInput.placeholder = "Enter new password";
      authPasswordInput.autocomplete = "new-password";
      submitAuthBtn.textContent = "Save New Password";
    }
  }

  async function checkSessionStatus() {
    try {
      const res = await fetch("auth.php?action=status");
      const data = await res.json();
      currentUser = data.logged_in ? data.username : null;
      updateAuthUI();
    } catch (err) {
      console.warn("Auth status check failed:", err);
    }
  }

  tabLoginBtn.addEventListener("click", () => setAuthMode("login"));
  tabRegisterBtn.addEventListener("click", () => setAuthMode("register"));
  tabResetBtn.addEventListener("click", () => setAuthMode("reset"));

  inlineResetTriggerBtn.addEventListener("click", () => {
    setAuthMode("reset");
  });

  openAuthModalBtn.addEventListener("click", () => {
    setAuthMode("login");
    openModal(authModal);
  });

  openResetPwdBtn.addEventListener("click", () => {
    setAuthMode("reset");
    openModal(authModal);
  });

  closeAuthModalBtn.addEventListener("click", () => closeModal(authModal));

  copyRecoveryKeyBtn.addEventListener("click", async () => {
    await navigator.clipboard.writeText(recoveryKeyValue.textContent);
    copyRecoveryKeyBtn.textContent = "Copied!";
    setTimeout(() => (copyRecoveryKeyBtn.textContent = "Copy"), 2000);
  });

  submitAuthBtn.addEventListener("click", async () => {
    clearAuthFeedback();
    submitAuthBtn.disabled = true;

    try {
      const formData = new FormData();
      const targetUser = (currentUser && authMode === "reset")
        ? currentUser
        : authUsernameInput.value.trim();

      formData.append("username", targetUser);

      if (authMode === "reset") {
        formData.append("action", "reset_password");
        formData.append("new_password", authPasswordInput.value);
        formData.append("recovery_key", authRecoveryKeyInput.value.trim());
      } else {
        formData.append("action", authMode);
        formData.append("password", authPasswordInput.value);
      }

      const res = await fetch("auth.php", { method: "POST", body: formData });
      const rawText = await res.text();
      let data;
      try {
        data = JSON.parse(rawText);
      } catch {
        throw new Error(`Server returned HTTP ${res.status} (non-JSON).`);
      }

      if (!res.ok || !data.success) {
        const err = new Error(data.error || "Authentication failed");
        err.code = data.error_code || "";
        throw err;
      }

      currentUser = data.username;
      authPasswordInput.value = "";
      authRecoveryKeyInput.value = "";
      updateAuthUI();

      // If registering or resetting password, show the new recovery key before closing
      if (data.recovery_key) {
        recoveryKeyTitle.textContent = authMode === "register"
          ? "Account Created! Save Your Recovery Key:"
          : "Password Updated! Save Your New Recovery Key:";
        recoveryKeyValue.textContent = data.recovery_key;
        recoveryKeyBox.style.display = "block";
        submitAuthBtn.style.display = "none";
      } else {
        closeModal(authModal);
      }
    } catch (err) {
      authErrorMsg.textContent = err.message;
      authErrorBox.style.display = "block";
      if (err.code === "WRONG_PASSWORD") {
        inlineResetTriggerBtn.style.display = "inline-block";
      }
    } finally {
      submitAuthBtn.disabled = false;
    }
  });

  logoutBtn.addEventListener("click", async () => {
    await fetch("auth.php?action=logout");
    currentUser = null;
    updateAuthUI();
  });

  openSaveModalBtn.addEventListener("click", () => {
    if (!currentUser) {
      setAuthMode("login");
      openModal(authModal);
      return;
    }
    shareResultBox.style.display = "none";
    lockedDirDisplay.textContent = `/trip/${currentUser}/`;
    openModal(saveModal);
  });

  closeSaveModalBtn.addEventListener("click", () => closeModal(saveModal));
  copySavedUrlBtn.addEventListener("click", async () => {
    await navigator.clipboard.writeText(generatedShareUrl.value);
    copySavedUrlBtn.textContent = "Copied!";
    setTimeout(() => (copySavedUrlBtn.textContent = "Copy Link"), 2000);
  });

  async function saveToDirectory(saveMode) {
    if (!currentUser) {
      closeModal(saveModal);
      setAuthMode("login");
      openModal(authModal);
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
        statusHeader.textContent = `Saved ${data.saved_photos.length} image(s) to /trip/${currentUser}/${tripSlug}/`;
        generatedShareUrl.value = `${window.location.origin}/trip/${currentUser}/${tripSlug}/`;
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

  closeMyTripsBtn.addEventListener("click", () => closeModal(myTripsModal));

  async function fetchAndRenderMyTrips() {
    if (!currentUser) return;
    myTripsDirLabel.textContent = `/trip/${currentUser}/`;
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
        myTripsList.innerHTML = `<p style="font-size: 0.85rem; color: #5f6368; padding: 12px 0;">No trips saved yet in <code>/trip/${escapeHtml(currentUser)}/</code>. Upload photos and click <b>Save to Site</b> to store your first trip.</p>`;
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
            closeModal(myTripsModal);
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
          if (!confirm(`Delete "${trip.slug}" permanently from /trip/${currentUser}/?`)) return;
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
    openModal(myTripsModal);
    fetchAndRenderMyTrips();
  });

  if (openUsageBtn) {
    openUsageBtn.addEventListener("click", () => {
      window.location.href = "usage.html";
    });
  }

  return { checkSessionStatus };
}
