import { buildKmzBlob, escapeHtml } from "./kmz.js";

/**
 * Account UI: email + password sign-in, registration, password reset by email,
 * password change, saving trips and listing them.
 *
 * The server (auth.php) is the source of truth. Every state-changing request
 * carries the CSRF token returned by action=status.
 */
export function initAccountSystem({ getActivePhotos, isConnectPathChecked, onLoadSavedKmz }) {
  const state = { user: null, csrf: null };
  let mode = "login"; // 'login' | 'register' | 'forgot' | 'change'

  /* ---------------------------------------------------------- elements -- */
  const userBadge = document.getElementById("userBadge");
  const openAuthModalBtn = document.getElementById("openAuthModalBtn");
  const openMyTripsBtn = document.getElementById("openMyTripsBtn");
  const openUsageBtn = document.getElementById("openUsageBtn");
  const openChangePwdBtn = document.getElementById("openChangePwdBtn");
  const logoutBtn = document.getElementById("logoutBtn");
  const openSaveModalBtn = document.getElementById("openSaveModalBtn");

  const verifyBanner = document.getElementById("verifyBanner");
  const resendVerifyBtn = document.getElementById("resendVerifyBtn");

  const authModal = document.getElementById("authModal");
  const tabLoginBtn = document.getElementById("tabLoginBtn");
  const tabRegisterBtn = document.getElementById("tabRegisterBtn");
  const authModalHeading = document.getElementById("authModalHeading");
  const authModalSubtext = document.getElementById("authModalSubtext");

  const authEmailGroup = document.getElementById("authEmailGroup");
  const authEmailInput = document.getElementById("authEmailInput");
  const authCurrentPasswordGroup = document.getElementById("authCurrentPasswordGroup");
  const authCurrentPasswordInput = document.getElementById("authCurrentPasswordInput");
  const authPasswordGroup = document.getElementById("authPasswordGroup");
  const authPasswordLabel = document.getElementById("authPasswordLabel");
  const authPasswordInput = document.getElementById("authPasswordInput");

  const forgotPasswordRow = document.getElementById("forgotPasswordRow");
  const forgotPasswordLink = document.getElementById("forgotPasswordLink");

  const authErrorBox = document.getElementById("authErrorBox");
  const authErrorMsg = document.getElementById("authErrorMsg");
  const authNoticeBox = document.getElementById("authNoticeBox");
  const authNoticeMsg = document.getElementById("authNoticeMsg");

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

  /* ----------------------------------------------------------- helpers -- */
  function openModal(el) {
    el.classList.add("open");
    el.style.display = "flex";
  }

  function closeModal(el) {
    el.classList.remove("open");
    el.style.display = "none";
  }

  function clearFeedback() {
    authErrorBox.style.display = "none";
    authNoticeBox.style.display = "none";
  }

  function showError(message) {
    authErrorMsg.textContent = message;
    authErrorBox.style.display = "block";
    authNoticeBox.style.display = "none";
  }

  function showNotice(message) {
    authNoticeMsg.textContent = message;
    authNoticeBox.style.display = "block";
    authErrorBox.style.display = "none";
  }

  /**
   * POST to auth.php with the CSRF token and parse the JSON response.
   */
  async function api(action, fields = {}) {
    const body = new FormData();
    body.append("action", action);
    Object.entries(fields).forEach(([key, value]) => body.append(key, value ?? ""));

    const headers = state.csrf ? { "X-CSRF-Token": state.csrf } : {};
    const res = await fetch("auth.php", {
      method: "POST",
      body,
      headers,
      credentials: "same-origin",
    });

    const rawText = await res.text();
    let data;
    try {
      data = JSON.parse(rawText);
    } catch {
      throw new Error(`Server returned HTTP ${res.status} (non-JSON).`);
    }

    if (!res.ok || !data.success) {
      const err = new Error(data.error || "Request failed");
      err.code = data.error_code || "";
      err.status = res.status;
      throw err;
    }

    if (data.csrf) state.csrf = data.csrf;
    return data;
  }

  function applyStatus(data) {
    if (data.csrf) state.csrf = data.csrf;
    state.user = data.logged_in
      ? {
          email: data.email || "",
          folderSlug: data.folder_slug || "",
          verified: Boolean(data.email_verified),
        }
      : null;
    updateUI();
  }

  function updateUI() {
    const user = state.user;

    if (user) {
      userBadge.textContent = user.email;
      userBadge.title = user.folderSlug ? `/trip/${user.folderSlug}/` : "";
      userBadge.style.display = "inline";
      userBadge.style.maxWidth = "190px";
      userBadge.style.overflow = "hidden";
      userBadge.style.textOverflow = "ellipsis";
      userBadge.style.whiteSpace = "nowrap";

      openAuthModalBtn.style.display = "none";
      openMyTripsBtn.style.display = "inline-block";
      if (openUsageBtn) openUsageBtn.style.display = "inline-block";
      openChangePwdBtn.style.display = "inline-block";
      logoutBtn.style.display = "inline-block";
      verifyBanner.style.display = user.verified ? "none" : "flex";
    } else {
      userBadge.style.display = "none";
      openAuthModalBtn.style.display = "inline-block";
      openMyTripsBtn.style.display = "none";
      if (openUsageBtn) openUsageBtn.style.display = "none";
      openChangePwdBtn.style.display = "none";
      logoutBtn.style.display = "none";
      verifyBanner.style.display = "none";
    }
  }

  function setMode(next) {
    mode = next;
    clearFeedback();

    tabLoginBtn.classList.toggle("secondary", next !== "login");
    tabRegisterBtn.classList.toggle("secondary", next !== "register");

    const showEmail = next === "login" || next === "register" || next === "forgot";
    authEmailGroup.style.display = showEmail ? "flex" : "none";
    authCurrentPasswordGroup.style.display = next === "change" ? "flex" : "none";
    authPasswordGroup.style.display = next === "forgot" ? "none" : "flex";
    forgotPasswordRow.style.display = next === "login" ? "block" : "none";

    if (next === "login") {
      authModalHeading.textContent = "Sign In";
      authModalSubtext.innerHTML =
        "Your saved .KMZ files and photos are stored in your private <code>/trip/&lt;your-folder&gt;/</code> directory.";
      authPasswordLabel.textContent = "Password";
      authPasswordInput.placeholder = "Enter your password";
      authPasswordInput.autocomplete = "current-password";
      submitAuthBtn.textContent = "Login";
    } else if (next === "register") {
      authModalHeading.textContent = "Create an Account";
      authModalSubtext.innerHTML =
        "We'll email you a confirmation link. You can browse right away, but saving trips needs a confirmed address.";
      authPasswordLabel.textContent = "Create Password (min 10 characters)";
      authPasswordInput.placeholder = "Choose a strong password";
      authPasswordInput.autocomplete = "new-password";
      submitAuthBtn.textContent = "Register";
    } else if (next === "forgot") {
      authModalHeading.textContent = "Reset Your Password";
      authModalSubtext.textContent =
        "Enter the email address on your account and we'll send you a link to choose a new password.";
      submitAuthBtn.textContent = "Send Reset Link";
    } else if (next === "change") {
      authModalHeading.textContent = "Change Password";
      authModalSubtext.textContent = state.user
        ? `Signed in as ${state.user.email}. Enter your current password to set a new one.`
        : "Enter your current password to set a new one.";
      authPasswordLabel.textContent = "New Password (min 10 characters)";
      authPasswordInput.placeholder = "Choose a new password";
      authPasswordInput.autocomplete = "new-password";
      submitAuthBtn.textContent = "Save New Password";
    }
  }

  async function checkSessionStatus() {
    try {
      const res = await fetch("auth.php?action=status", { credentials: "same-origin" });
      const data = await res.json();
      applyStatus(data);
    } catch (err) {
      console.warn("Auth status check failed:", err);
    }
  }

  /* ------------------------------------------------------------- events -- */
  tabLoginBtn.addEventListener("click", () => setMode("login"));
  tabRegisterBtn.addEventListener("click", () => setMode("register"));

  forgotPasswordLink.addEventListener("click", (event) => {
    event.preventDefault();
    authEmailInput.value = state.user ? state.user.email : authEmailInput.value;
    setMode("forgot");
  });

  openAuthModalBtn.addEventListener("click", () => {
    setMode("login");
    openModal(authModal);
  });

  openChangePwdBtn.addEventListener("click", () => {
    setMode("change");
    authCurrentPasswordInput.value = "";
    authPasswordInput.value = "";
    openModal(authModal);
  });

  closeAuthModalBtn.addEventListener("click", () => closeModal(authModal));

  [authEmailInput, authCurrentPasswordInput, authPasswordInput].forEach((input) => {
    input.addEventListener("keydown", (event) => {
      if (event.key === "Enter") {
        event.preventDefault();
        submitAuthBtn.click();
      }
    });
  });

  submitAuthBtn.addEventListener("click", async () => {
    clearFeedback();

    const email = authEmailInput.value.trim();
    const password = authPasswordInput.value;
    const currentPassword = authCurrentPasswordInput.value;

    submitAuthBtn.disabled = true;
    const originalLabel = submitAuthBtn.textContent;
    submitAuthBtn.textContent = "Working...";

    try {
      if (mode === "login") {
        const data = await api("login", { email, password });
        applyStatus(data);
        authPasswordInput.value = "";
        closeModal(authModal);
      } else if (mode === "register") {
        const data = await api("register", { email, password });
        if (data.logged_in) applyStatus(data);
        authPasswordInput.value = "";
        showNotice(data.message || "Check your inbox to confirm your address.");
      } else if (mode === "forgot") {
        const data = await api("forgot", { email });
        showNotice(data.message || "If that address has an account, we sent a reset link.");
      } else if (mode === "change") {
        const data = await api("change_password", {
          current_password: currentPassword,
          new_password: password,
        });
        authCurrentPasswordInput.value = "";
        authPasswordInput.value = "";
        showNotice(data.message || "Password updated.");
      }
    } catch (err) {
      showError(err.message);
      if (err.code === "EMAIL_UNVERIFIED" && state.user) {
        state.user.verified = false;
        updateUI();
      }
    } finally {
      submitAuthBtn.disabled = false;
      submitAuthBtn.textContent = originalLabel;
    }
  });

  logoutBtn.addEventListener("click", async () => {
    try {
      await api("logout");
    } catch (err) {
      console.warn("Logout failed:", err);
    }
    state.user = null;
    state.csrf = null;
    updateUI();
    await checkSessionStatus();
  });

  resendVerifyBtn.addEventListener("click", async () => {
    resendVerifyBtn.disabled = true;
    const originalLabel = resendVerifyBtn.textContent;
    resendVerifyBtn.textContent = "Sending...";
    try {
      await api("resend_verification");
      resendVerifyBtn.textContent = "Sent!";
      setTimeout(() => (resendVerifyBtn.textContent = originalLabel), 2500);
    } catch (err) {
      resendVerifyBtn.textContent = originalLabel;
      alert(err.message);
    } finally {
      resendVerifyBtn.disabled = false;
    }
  });

  /* --------------------------------------------------------------- save -- */
  openSaveModalBtn.addEventListener("click", () => {
    if (!state.user) {
      setMode("login");
      openModal(authModal);
      return;
    }
    shareResultBox.style.display = "none";
    lockedDirDisplay.textContent = `/trip/${state.user.folderSlug}/`;
    openModal(saveModal);
  });

  closeSaveModalBtn.addEventListener("click", () => closeModal(saveModal));

  copySavedUrlBtn.addEventListener("click", async () => {
    await navigator.clipboard.writeText(generatedShareUrl.value);
    copySavedUrlBtn.textContent = "Copied!";
    setTimeout(() => (copySavedUrlBtn.textContent = "Copy Link"), 2000);
  });

  async function saveToDirectory(saveMode) {
    if (!state.user) {
      closeModal(saveModal);
      setMode("login");
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

      const headers = state.csrf ? { "X-CSRF-Token": state.csrf } : {};
      const res = await fetch("save_trip.php", {
        method: "POST",
        body: formData,
        headers,
        credentials: "same-origin",
      });
      const rawText = await res.text();
      let data;
      try {
        data = JSON.parse(rawText);
      } catch {
        throw new Error(`Server returned HTTP ${res.status} (non-JSON).`);
      }

      if (!res.ok || !data.success) {
        const err = new Error(data.error || "Upload failed");
        err.code = data.error_code || "";
        throw err;
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
        statusHeader.textContent = `Saved ${data.saved_photos.length} image(s) to /trip/${data.folder_slug}/${tripSlug}/`;
        generatedShareUrl.value = `${window.location.origin}/trip/${data.folder_slug}/${tripSlug}/`;
        downloadHostedKmzLink.style.display = "none";
      }
    } catch (err) {
      if (err.code === "EMAIL_UNVERIFIED" && state.user) {
        state.user.verified = false;
        updateUI();
      }
      alert(`Save failed: ${err.message}`);
    } finally {
      saveKmzOnlyBtn.disabled = false;
      saveImagesOnlyBtn.disabled = false;
      activeBtn.textContent = origLabel;
    }
  }

  saveKmzOnlyBtn.addEventListener("click", () => saveToDirectory("kmz_only"));
  saveImagesOnlyBtn.addEventListener("click", () => saveToDirectory("images_only"));

  /* ---------------------------------------------------------- my trips -- */
  closeMyTripsBtn.addEventListener("click", () => closeModal(myTripsModal));

  async function fetchAndRenderMyTrips() {
    if (!state.user) return;

    const folderSlug = state.user.folderSlug;
    myTripsDirLabel.textContent = `/trip/${folderSlug}/`;
    myTripsList.innerHTML = `<p style="font-size: 0.85rem; color: #5f6368;">Loading saved trips...</p>`;

    try {
      const res = await fetch("my_trips.php?action=list", { credentials: "same-origin" });
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
        myTripsList.innerHTML = `<p style="font-size: 0.85rem; color: #5f6368; padding: 12px 0;">No trips saved yet in <code>/trip/${escapeHtml(folderSlug)}/</code>. Upload photos and click <b>Save to Site</b> to store your first trip.</p>`;
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
          if (!confirm(`Delete "${trip.slug}" permanently from /trip/${folderSlug}/?`)) return;
          delBtn.disabled = true;
          delBtn.textContent = "Deleting...";
          try {
            const formData = new FormData();
            formData.append("action", "delete");
            formData.append("slug", trip.slug);
            const headers = state.csrf ? { "X-CSRF-Token": state.csrf } : {};
            const delRes = await fetch("my_trips.php", {
              method: "POST",
              body: formData,
              headers,
              credentials: "same-origin",
            });
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
    if (!state.user) return;
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
