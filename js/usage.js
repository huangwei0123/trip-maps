import { escapeHtml } from "./kmz.js";

const loadingEl = document.getElementById("usageLoading");
const errorEl = document.getElementById("usageError");
const contentEl = document.getElementById("usageContent");
const cardsEl = document.getElementById("usageCards");
const tableBodyEl = document.getElementById("usageTableBody");
const activityEl = document.getElementById("usageActivity");
const scopeEl = document.getElementById("usageScope");
const userBadgeEl = document.getElementById("usageUserBadge");

const EVENT_LABELS = {
  register: "created an account",
  login: "signed in",
  logout: "signed out",
  password_reset: "reset their password",
  link_created: "created a share link",
  trip_deleted: "deleted a trip",
};

function formatDateTime(value) {
  if (!value) return "—";
  const date = new Date(value);
  if (isNaN(date)) return String(value);
  return date.toLocaleString();
}

function relativeTime(value) {
  if (!value) return "";
  const date = new Date(value);
  if (isNaN(date)) return "";

  const diffMs = Date.now() - date.getTime();
  if (diffMs < 60000) return "just now";

  const minutes = Math.floor(diffMs / 60000);
  if (minutes < 60) return `${minutes}m ago`;

  const hours = Math.floor(minutes / 60);
  if (hours < 24) return `${hours}h ago`;

  const days = Math.floor(hours / 24);
  if (days < 30) return `${days}d ago`;

  const months = Math.floor(days / 30);
  if (months < 12) return `${months}mo ago`;

  return `${Math.floor(months / 12)}y ago`;
}

function eventLabel(event) {
  return EVENT_LABELS[event] || String(event).replace(/_/g, " ");
}

function renderCards(totals, isAdmin) {
  const cards = [
    { label: isAdmin ? "People" : "Your account", value: isAdmin ? totals.users : 1 },
    { label: "Logins", value: totals.logins },
    { label: "Links created", value: totals.links },
    { label: "Storage used", value: totals.storage_human },
  ];

  cardsEl.innerHTML = cards
    .map(
      (card) => `
        <div class="usage-card">
          <span class="usage-card-value">${escapeHtml(String(card.value))}</span>
          <span class="usage-card-label">${escapeHtml(card.label)}</span>
        </div>
      `
    )
    .join("");
}

function renderTable(users) {
  if (!users.length) {
    tableBodyEl.innerHTML = `<tr><td colspan="7" class="usage-empty">No accounts yet.</td></tr>`;
    return;
  }

  tableBodyEl.innerHTML = users
    .map(
      (user) => `
        <tr>
          <td><span class="usage-user">${escapeHtml(user.username)}</span></td>
          <td>
            ${
              user.last_login
                ? `<span>${escapeHtml(relativeTime(user.last_login))}</span>
                   <span class="usage-muted">${escapeHtml(formatDateTime(user.last_login))}</span>`
                : `<span class="usage-muted">Never</span>`
            }
          </td>
          <td>${Number(user.login_count) || 0}</td>
          <td>${Number(user.links_created) || 0}</td>
          <td>${Number(user.trips) || 0}</td>
          <td>${Number(user.images) || 0}</td>
          <td>${escapeHtml(user.storage_human)}</td>
        </tr>
      `
    )
    .join("");
}

function renderActivity(recent) {
  if (!recent.length) {
    activityEl.innerHTML = `<li class="usage-empty">No activity recorded yet.</li>`;
    return;
  }

  activityEl.innerHTML = recent
    .map((item) => {
      let detail = "";
      if (item.event === "link_created" && item.path) {
        detail = ` <code>${escapeHtml(item.path)}</code>`;
        if (item.bytes_human) {
          detail += ` <span class="usage-muted-inline">(${escapeHtml(item.bytes_human)})</span>`;
        }
      } else if (item.event === "trip_deleted" && item.trip) {
        detail = ` <code>${escapeHtml(item.trip)}</code>`;
      }

      return `
        <li class="usage-activity-item">
          <span class="usage-dot usage-dot-${escapeHtml(item.event)}"></span>
          <div class="usage-activity-body">
            <div><b>${escapeHtml(item.user)}</b> ${escapeHtml(eventLabel(item.event))}${detail}</div>
            <div class="usage-activity-time" title="${escapeHtml(formatDateTime(item.ts))}">
              ${escapeHtml(relativeTime(item.ts))}
            </div>
          </div>
        </li>
      `;
    })
    .join("");
}

async function loadUsage() {
  try {
    const res = await fetch("stats.php?action=summary", {
      credentials: "same-origin",
      cache: "no-store",
    });
    const rawText = await res.text();

    let data;
    try {
      data = JSON.parse(rawText);
    } catch {
      throw new Error(`Server returned HTTP ${res.status} (non-JSON).`);
    }

    if (!res.ok || !data.success) {
      const err = new Error(data.error || `Request failed (HTTP ${res.status}).`);
      err.status = res.status;
      throw err;
    }

    loadingEl.style.display = "none";
    contentEl.style.display = "flex";

    userBadgeEl.textContent = data.viewer ? `/trip/${data.viewer}/` : "";
    scopeEl.textContent = data.is_admin
      ? "Showing all accounts"
      : "Showing your account only";

    renderCards(data.totals, data.is_admin);
    renderTable(data.users);
    renderActivity(data.recent);
  } catch (err) {
    loadingEl.style.display = "none";
    errorEl.style.display = "block";

    if (err.status === 401) {
      errorEl.innerHTML =
        'You need to be signed in to view usage statistics. <a href="index.html">Go to the map and log in</a>.';
    } else {
      errorEl.textContent = `Could not load usage data: ${err.message}`;
    }
  }
}

loadUsage();
