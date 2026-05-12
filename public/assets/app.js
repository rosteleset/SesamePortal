(function () {
  initMap();
  initPlayer();
  initPreviewRefresh();

  function initMap() {
    if (!window.L || !window.SESAME_CAMERAS || !document.getElementById("map")) return;

    const cameras = window.SESAME_CAMERAS || [];
    const first = cameras.find((camera) => Number.isFinite(camera.lat) && Number.isFinite(camera.lng));
    const map = L.map("map", { zoomControl: true });
    map.setView(first ? [first.lat, first.lng] : [25.2048, 55.2708], first ? 14 : 10);

    L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
      maxZoom: 19,
      attribution: "&copy; OpenStreetMap"
    }).addTo(map);

    cameras.forEach((camera) => {
      const icon = L.divIcon({
        className: "",
        html: `<div class="camera-marker" style="transform: rotate(${Number(camera.direction) || 0}deg)"><span style="transform: rotate(${-1 * (Number(camera.direction) || 0)}deg)">●</span></div>`,
        iconSize: [28, 28],
        iconAnchor: [14, 14]
      });

      const marker = L.marker([camera.lat, camera.lng], { icon }).addTo(map);
      marker.bindPopup(`
        <strong>${escapeHtml(camera.name)}</strong><br>
        <a href="${camera.player}">Открыть видео</a>
      `);
    });
  }

  function initPlayer() {
    const page = document.querySelector(".player-page");
    if (!page) return;

    const stage = page.querySelector(".player-stage") || page;
    const frame = page.querySelector(".player-frame");
    const button = page.querySelector(".player-fullscreen");
    const backUrl = page.dataset.backUrl || "/";
    const fullscreenTarget = (stage.requestFullscreen || stage.webkitRequestFullscreen) ? stage : frame;
    const requestFullscreen = fullscreenTarget?.requestFullscreen || fullscreenTarget?.webkitRequestFullscreen;
    const exitFullscreen = document.exitFullscreen || document.webkitExitFullscreen;
    const fullscreenElement = () => document.fullscreenElement || document.webkitFullscreenElement;

    if (button && requestFullscreen) {
      button.addEventListener("click", async () => {
        try {
          if (fullscreenElement() && exitFullscreen) {
            await exitFullscreen.call(document);
          } else {
            await requestFullscreen.call(fullscreenTarget);
          }
        } catch (_error) {
          button.blur();
        }
      });

      const syncFullscreenState = () => {
        const active = fullscreenElement() === fullscreenTarget;
        page.classList.toggle("is-fullscreen", active);
        button.textContent = active ? "Свернуть" : "На весь экран";
      };
      document.addEventListener("fullscreenchange", syncFullscreenState);
      document.addEventListener("webkitfullscreenchange", syncFullscreenState);
    } else if (button) {
      button.hidden = true;
    }

    [page.querySelector(".player-edge-swipe"), page.querySelector(".player-toolbar")]
      .filter(Boolean)
      .forEach((zone) => bindBackSwipe(zone, backUrl));
  }

  function bindBackSwipe(element, backUrl) {
    let startX = 0;
    let startY = 0;
    let startAt = 0;

    element.addEventListener("touchstart", (event) => {
      const touch = event.changedTouches[0];
      startX = touch.clientX;
      startY = touch.clientY;
      startAt = Date.now();
    }, { passive: true });

    element.addEventListener("touchend", (event) => {
      const touch = event.changedTouches[0];
      const dx = touch.clientX - startX;
      const dy = touch.clientY - startY;
      const elapsed = Date.now() - startAt;
      if (elapsed < 700 && dx > 72 && Math.abs(dy) < 55) {
        window.location.assign(backUrl);
      }
    }, { passive: true });
  }

  function initPreviewRefresh() {
    const images = Array.from(document.querySelectorAll("img[data-preview-src]"));
    if (!images.length) return;

    images.forEach((image, index) => {
      image.addEventListener("load", () => markPreviewReady(image));
      image.addEventListener("error", () => markPreviewMissing(image));
      const intervalMs = Math.max(10000, Number(image.dataset.previewRefreshMs) || 30000);
      window.setTimeout(function refreshLoop() {
        refreshPreview(image);
        window.setTimeout(refreshLoop, intervalMs);
      }, intervalMs + Math.min(index * 1200, intervalMs));
    });
  }

  function refreshPreview(image) {
    const source = image.dataset.previewSrc;
    if (!source) return;

    const separator = source.includes("?") ? "&" : "?";
    image.src = `${source}${separator}_=${Date.now()}`;
  }

  function markPreviewReady(image) {
    image.hidden = false;
    image.closest(".preview")?.classList.remove("no-preview");
  }

  function markPreviewMissing(image) {
    image.hidden = true;
    image.closest(".preview")?.classList.add("no-preview");
  }

  function escapeHtml(value) {
    return String(value)
      .replaceAll("&", "&amp;")
      .replaceAll("<", "&lt;")
      .replaceAll(">", "&gt;")
      .replaceAll('"', "&quot;");
  }
})();
