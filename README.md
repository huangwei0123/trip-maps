# PhotoOnMaps (trip-maps)

Turn geotagged photos into a 3D map, save trips into your own folder, and share them as links or Google Earth `.KMZ` files.

## Usage tracking

The `feature/usage-tracking` branch records lightweight usage statistics:

| Metric | Source |
| --- | --- |
| Login times | `register`, `login`, `logout`, `password_reset` events |
| Storage used | live scan of `trip/<username>/` |
| Links created | `link_created` event on every successful save in `save_trip.php` |
| Trips deleted | `trip_deleted` event from `my_trips.php` |

Events are appended to `stats/events.jsonl` (one JSON object per line, auto-rotated at ~2 MB). The `stats/` folder is denied direct web access with `.htaccess` and is ignored by git, along with `users/` and `trip/`.

### Dashboard

Open `usage.html` (also linked from the **Usage** button after login). A normal user sees only their own row and activity; an admin sees every account.

### Making an admin

Either:

1. Create `stats/admins.json` on the server:

   ```json
   ["your-username", "second-admin"]
   ```

2. Or set the environment variable `PHOTOONMAPS_ADMINS=your-username,second-admin`.

Usernames are normalized the same way as `auth.php` (lowercase, `a-z0-9_-` only).

### API

`GET stats.php?action=summary` — requires a logged-in session. Returns totals, per-user rows, and the 40 most recent events. Non-admins are always limited to their own data.

## Development

This folder is a git worktree of `huangwei0123/trip-maps` on branch `feature/usage-tracking`.

```powershell
& "C:\Program Files\Git\cmd\git.exe" status
```
