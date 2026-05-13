(function () {
  const messages = window.SESAME_I18N || {};
  initMap();
  initCameraPositionEditor();
  initPlayer();
  initPreviewRefresh();

  function initMap() {
    if (!window.L || !window.SESAME_CAMERAS || !document.getElementById("map")) return;

    const cameras = window.SESAME_CAMERAS || [];
    const visibleCameras = cameras.filter((camera) => Number.isFinite(camera.lat) && Number.isFinite(camera.lng));
    const map = L.map("map", { zoomControl: true });
    map.setView([25.2048, 55.2708], 10);

    L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
      maxZoom: 19,
      attribution: "&copy; OpenStreetMap"
    }).addTo(map);

    visibleCameras.forEach((camera) => {
      const marker = L.marker([camera.lat, camera.lng], { icon: cameraIcon(camera.direction) }).addTo(map);
      marker.bindPopup(cameraPopupHtml(camera), { className: "camera-popup", maxWidth: 280 });
    });

    fitMapToCameras(map, visibleCameras);
    map.on("popupopen", (event) => initPreviewRefresh(event.popup.getElement()));
  }

  function initCameraPositionEditor() {
    const container = document.getElementById("camera-position-map");
    if (!window.L || !container) return;

    const latInput = document.getElementById("camera-latitude") || document.querySelector('input[name="latitude"]');
    const lngInput = document.getElementById("camera-longitude") || document.querySelector('input[name="longitude"]');
    const directionInput = document.getElementById("camera-direction") || document.querySelector('input[name="direction_deg"]');
    if (!latInput || !lngInput) return;

    let committed = readEditorState();
    let pending = null;
    const start = hasPoint(committed) ? [committed.lat, committed.lng] : [25.2048, 55.2708];
    const editorMap = L.map(container, { zoomControl: true }).setView(start, hasPoint(committed) ? 16 : 4);
    const confirmBar = createMapConfirmBar(container);
    let cameraMarker = null;
    let directionMarker = null;
    let directionLine = null;

    L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
      maxZoom: 19,
      attribution: "&copy; OpenStreetMap"
    }).addTo(editorMap);

    const stageChange = (next, pan = false) => {
      pending = normalizeEditorState(next);
      renderEditorState(pending);
      showMapConfirm(confirmBar, true);
      if (pan && hasPoint(pending)) {
        editorMap.setView([pending.lat, pending.lng], Math.max(editorMap.getZoom(), 15));
      }
    };

    const syncFromInputs = () => {
      committed = readEditorState();
      pending = null;
      renderEditorState(committed);
      showMapConfirm(confirmBar, false);
      if (hasPoint(committed)) {
        editorMap.setView([committed.lat, committed.lng], Math.max(editorMap.getZoom(), 15));
      }
    };

    renderEditorState(committed);
    editorMap.on("click", (event) => {
      const base = pending || committed;
      stageChange({ ...base, lat: event.latlng.lat, lng: event.latlng.lng });
    });
    editorMap.on("zoomend", () => renderEditorState(pending || committed));
    latInput.addEventListener("change", syncFromInputs);
    lngInput.addEventListener("change", syncFromInputs);
    directionInput?.addEventListener("change", syncFromInputs);
    confirmBar.querySelector(".camera-map-apply")?.addEventListener("click", () => {
      if (!pending) return;
      committed = pending;
      pending = null;
      writeEditorState(committed);
      renderEditorState(committed);
      showMapConfirm(confirmBar, false);
    });
    confirmBar.querySelector(".camera-map-cancel")?.addEventListener("click", () => {
      pending = null;
      renderEditorState(committed);
      showMapConfirm(confirmBar, false);
    });
    document.querySelector(".camera-map-clear")?.addEventListener("click", () => {
      stageChange({ ...committed, lat: NaN, lng: NaN });
    });
    container.closest("form")?.addEventListener("submit", (event) => {
      if (!pending) return;
      event.preventDefault();
      showMapConfirm(confirmBar, true, true);
    });
    window.setTimeout(() => editorMap.invalidateSize(), 0);

    function readEditorState() {
      return normalizeEditorState({
        lat: toNumber(latInput.value),
        lng: toNumber(lngInput.value),
        direction: directionInput ? toNumber(directionInput.value) : 0
      });
    }

    function writeEditorState(state) {
      if (hasPoint(state)) {
        latInput.value = state.lat.toFixed(7);
        lngInput.value = state.lng.toFixed(7);
      } else {
        latInput.value = "";
        lngInput.value = "";
      }
      if (directionInput) {
        directionInput.value = String(state.direction);
      }
    }

    function renderEditorState(state) {
      if (!hasPoint(state)) {
        removeLayer(cameraMarker);
        removeLayer(directionMarker);
        removeLayer(directionLine);
        cameraMarker = null;
        directionMarker = null;
        directionLine = null;
        return;
      }

      const center = L.latLng(state.lat, state.lng);
      const target = directionTarget(center, state.direction, editorMap);
      if (!cameraMarker) {
        cameraMarker = L.marker(center, { draggable: true, icon: cameraIcon(state.direction) }).addTo(editorMap);
        cameraMarker.on("dragend", () => {
          const latlng = cameraMarker.getLatLng();
          const base = pending || committed;
          stageChange({ ...base, lat: latlng.lat, lng: latlng.lng });
        });
      } else {
        cameraMarker.setLatLng(center);
        cameraMarker.setIcon(cameraIcon(state.direction));
      }

      if (!directionMarker) {
        directionMarker = L.marker(target, { draggable: true, icon: directionHandleIcon(), zIndexOffset: 1000 }).addTo(editorMap);
        directionMarker.on("dragend", () => {
          const base = pending || committed;
          if (!hasPoint(base)) return;
          stageChange({ ...base, direction: bearingFromTarget(L.latLng(base.lat, base.lng), directionMarker.getLatLng(), editorMap) });
        });
      } else {
        directionMarker.setLatLng(target);
      }

      if (!directionLine) {
        directionLine = L.polyline([center, target], {
          color: "#C1964E",
          weight: 3,
          opacity: 0.9,
          dashArray: "6 6",
          className: "camera-direction-line"
        }).addTo(editorMap);
      } else {
        directionLine.setLatLngs([center, target]);
      }
    }
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
        const label = active ? tr("collapse", "Свернуть") : tr("fullscreen", "На весь экран");
        page.classList.toggle("is-fullscreen", active);
        button.textContent = label;
        button.setAttribute("aria-label", label);
        button.setAttribute("title", label);
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

  function createMapConfirmBar(container) {
    const field = container.closest(".camera-position-field") || container.parentElement;
    const bar = document.createElement("div");
    bar.className = "camera-map-confirm";
    bar.hidden = true;
    bar.innerHTML = `
      <span>${escapeHtml(tr("mapChangePending", "Подтвердите изменение на карте"))}</span>
      <div>
        <button type="button" class="primary camera-map-apply">${escapeHtml(tr("apply", "Применить"))}</button>
        <button type="button" class="camera-map-cancel">${escapeHtml(tr("cancel", "Отменить"))}</button>
      </div>
    `;
    field.insertBefore(bar, container);
    return bar;
  }

  function showMapConfirm(bar, visible, attention = false) {
    bar.hidden = !visible;
    bar.classList.toggle("attention", Boolean(visible && attention));
    if (visible && attention) {
      bar.scrollIntoView({ block: "nearest" });
    }
  }

  function fitMapToCameras(map, cameras) {
    if (!cameras.length) return;

    if (cameras.length === 1) {
      map.setView([cameras[0].lat, cameras[0].lng], 16);
      return;
    }

    const bounds = L.latLngBounds(cameras.map((camera) => [camera.lat, camera.lng]));
    const padding = map.getSize().x < 520 ? [28, 28] : [52, 52];
    map.fitBounds(bounds, {
      paddingTopLeft: padding,
      paddingBottomRight: padding,
      maxZoom: 16
    });
  }

  function normalizeEditorState(state) {
    return {
      lat: toNumber(state.lat),
      lng: toNumber(state.lng),
      direction: normalizeDirection(state.direction ?? state.dir ?? 0)
    };
  }

  function hasPoint(state) {
    return Number.isFinite(state.lat) && Number.isFinite(state.lng);
  }

  function directionTarget(center, direction, map) {
    const point = map.latLngToLayerPoint(center);
    const radians = normalizeDirection(direction) * Math.PI / 180;
    return map.layerPointToLatLng(L.point(
      point.x + Math.sin(radians) * 72,
      point.y - Math.cos(radians) * 72
    ));
  }

  function bearingFromTarget(center, target, map) {
    const a = map.latLngToLayerPoint(center);
    const b = map.latLngToLayerPoint(target);
    const dx = b.x - a.x;
    const dy = b.y - a.y;
    if (Math.abs(dx) < 1 && Math.abs(dy) < 1) return 0;
    return normalizeDirection(Math.atan2(dx, -dy) * 180 / Math.PI);
  }

  function removeLayer(layer) {
    if (layer) {
      layer.remove();
    }
  }

  function normalizeDirection(value) {
    const number = Number(value);
    if (!Number.isFinite(number)) return 0;
    return ((Math.round(number) % 360) + 360) % 360;
  }

  function cameraIcon(direction) {
    const angle = Number(direction) || 0;
    return L.divIcon({
      className: "",
      html: `<div class="camera-marker" style="transform: rotate(${angle}deg)"><span style="transform: rotate(${-1 * angle}deg)">●</span></div>`,
      iconSize: [28, 28],
      iconAnchor: [14, 14]
    });
  }

  function directionHandleIcon() {
    return L.divIcon({
      className: "",
      html: '<div class="camera-direction-handle"></div>',
      iconSize: [22, 22],
      iconAnchor: [11, 11]
    });
  }

  function cameraPopupHtml(camera) {
    const preview = camera.preview
      ? `<a class="map-popup-preview" href="${escapeHtml(camera.player)}"><img src="${escapeHtml(camera.preview)}" data-preview-src="${escapeHtml(camera.preview)}" data-preview-refresh="off" alt="" loading="lazy"><span class="map-popup-preview-state">${escapeHtml(tr("previewUnavailable", "Превью недоступно"))}</span></a>`
      : `<div class="map-popup-preview no-preview"><span class="map-popup-preview-state">${escapeHtml(tr("previewUnavailable", "Превью недоступно"))}</span></div>`;

    return `
      <div class="map-popup-card">
        ${preview}
        <strong>${escapeHtml(camera.name)}</strong>
        <span>${escapeHtml(camera.server || "")}</span>
        <a href="${escapeHtml(camera.player)}">${escapeHtml(tr("openVideo", "Открыть видео"))}</a>
      </div>
    `;
  }

  function initPreviewRefresh(root = document) {
    const images = Array.from(root.querySelectorAll("img[data-preview-src]"));
    if (!images.length) return;

    images.forEach((image, index) => {
      if (image.dataset.previewRefreshBound === "1") return;
      image.dataset.previewRefreshBound = "1";
      image.addEventListener("load", () => markPreviewReady(image));
      image.addEventListener("error", () => markPreviewMissing(image));
      if (image.complete) {
        if (image.naturalWidth > 0) {
          markPreviewReady(image);
        } else {
          markPreviewMissing(image);
        }
      }
      if (image.dataset.previewRefresh === "off") return;
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
    image.closest(".preview, .map-popup-preview")?.classList.remove("no-preview");
  }

  function markPreviewMissing(image) {
    image.hidden = true;
    image.closest(".preview, .map-popup-preview")?.classList.add("no-preview");
  }

  function toNumber(value) {
    if (value === null || value === undefined || value === "") return NaN;
    return Number(String(value).replace(",", "."));
  }

  function escapeHtml(value) {
    return String(value)
      .replaceAll("&", "&amp;")
      .replaceAll("<", "&lt;")
      .replaceAll(">", "&gt;")
      .replaceAll('"', "&quot;");
  }

  function tr(key, fallback) {
    return messages[key] || fallback;
  }
})();
