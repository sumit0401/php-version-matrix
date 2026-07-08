const imageGrid = document.getElementById('image-grid');
const buildLog = document.getElementById('build-log');
const phpSelect = document.getElementById('phpVersion');
const mysqlSelect = document.getElementById('mysqlVersion');
const form = document.getElementById('new-instance-form');
const startBtn = document.getElementById('start-btn');
const startError = document.getElementById('start-error');
const instancesTable = document.getElementById('instances-table');
const instancesBody = document.getElementById('instances-body');
const noInstances = document.getElementById('no-instances');
const logsModal = document.getElementById('logs-modal');
const logsTitle = document.getElementById('logs-title');
const logsBody = document.getElementById('logs-body');
document.getElementById('logs-close').addEventListener('click', () => logsModal.classList.add('hidden'));

const buildPollers = {};

async function getJson(action, params) {
  const qs = new URLSearchParams({ action, ...(params || {}) });
  const res = await fetch(`index.php?${qs}`);
  const body = await res.json();
  if (!res.ok) throw new Error(body.error || res.statusText);
  return body;
}

async function postForm(action, data) {
  const res = await fetch(`index.php?action=${encodeURIComponent(action)}`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams(data || {}),
  });
  const body = await res.json();
  if (!res.ok) throw new Error(body.error || res.statusText);
  return body;
}

function statusBadge(status) {
  return `<span class="badge ${status}">${status}</span>`;
}

function escapeHtml(str) {
  return String(str).replace(/[&<>"']/g, (c) => ({
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#39;',
  }[c]));
}

async function refreshImages() {
  const images = await getJson('images');
  imageGrid.innerHTML = Object.entries(images)
    .map(([version, info]) => {
      const label = info.building ? 'Building...' : info.built ? 'Built' : 'Not built';
      return `
        <div class="image-card" data-version="${version}">
          <div class="version">PHP ${version}</div>
          <div class="status ${info.built ? 'built' : ''}">${label}</div>
          <button class="secondary" data-build="${version}" ${info.building ? 'disabled' : ''}>
            ${info.built ? 'Rebuild' : 'Build'}
          </button>
        </div>
      `;
    })
    .join('');
  imageGrid.querySelectorAll('[data-build]').forEach((btn) => {
    btn.addEventListener('click', () => buildImage(btn.getAttribute('data-build')));
  });
  // Resume polling for any version still building (e.g. after a page reload).
  Object.entries(images).forEach(([version, info]) => {
    if (info.building && !buildPollers[version]) {
      buildLog.classList.remove('hidden');
      pollBuild(version);
    }
  });
}

async function buildImage(version) {
  buildLog.classList.remove('hidden');
  buildLog.textContent = `Building PHP ${version} image...\n`;
  await postForm('build', { php: version });
  await refreshImages();
  pollBuild(version);
}

function pollBuild(version) {
  buildPollers[version] = setInterval(async () => {
    const status = await getJson('build_status', { php: version });
    buildLog.textContent = status.log || '';
    buildLog.scrollTop = buildLog.scrollHeight;
    if (status.done) {
      clearInterval(buildPollers[version]);
      delete buildPollers[version];
      buildLog.textContent += status.ok ? '\n✔ Build complete.\n' : '\n✘ Build failed.\n';
      refreshImages();
    }
  }, 1500);
}

async function refreshInstances() {
  const list = await getJson('instances');
  noInstances.classList.toggle('hidden', list.length > 0);
  instancesTable.classList.toggle('hidden', list.length === 0);
  instancesBody.innerHTML = list
    .map(
      (i) => `
      <tr>
        <td>${escapeHtml(i.label)}</td>
        <td>${i.phpVersion}</td>
        <td>${i.mysqlVersion}</td>
        <td class="folder" title="${escapeHtml(i.folderPath)}">${escapeHtml(i.folderPath)}</td>
        <td>${statusBadge(i.status)}</td>
        <td><a href="http://localhost:${i.phpPort}/" target="_blank">localhost:${i.phpPort}</a></td>
        <td class="db-cell">
          <div class="db-line">
            <span class="db-tag">in app config</span>
            <code title="Use this as the DB server address inside your app's own settings/installer — it's on the same Docker network.">${escapeHtml(i.dbName)}</code>
          </div>
          <div class="db-line">
            <span class="db-tag">from host</span>
            <a href="/adminer.php?server=127.0.0.1:${i.dbPort}&amp;username=qloapps&amp;db=qloapps" target="_blank" rel="noopener" title="Adminer / mysql CLI / GUI client from your Linux host">localhost:${i.dbPort}</a>
          </div>
        </td>
        <td>
          <button class="secondary" data-logs="${i.id}" data-container="php">PHP log</button>
          <button class="secondary" data-logs="${i.id}" data-container="db">DB log</button>
        </td>
        <td><button class="danger" data-stop="${i.id}">Stop</button></td>
      </tr>
    `
    )
    .join('');

  instancesBody.querySelectorAll('[data-stop]').forEach((btn) => {
    btn.addEventListener('click', () => stopInstance(btn.getAttribute('data-stop')));
  });
  instancesBody.querySelectorAll('[data-logs]').forEach((btn) => {
    btn.addEventListener('click', () =>
      showLogs(btn.getAttribute('data-logs'), btn.getAttribute('data-container'))
    );
  });
}

async function showLogs(id, container) {
  logsTitle.textContent = `${container.toUpperCase()} logs — ${id}`;
  logsBody.textContent = 'Loading...';
  logsModal.classList.remove('hidden');
  const { logs } = await getJson('logs', { id, container });
  logsBody.textContent = logs || '(empty)';
}

async function stopInstance(id) {
  if (!confirm('Stop and remove this instance?')) return;
  await postForm('stop_instance', { id });
  refreshInstances();
}

form.addEventListener('submit', async (evt) => {
  evt.preventDefault();
  startError.textContent = '';
  startBtn.disabled = true;
  try {
    const folderPath = document.getElementById('folderPath').value.trim();
    const phpVersion = phpSelect.value;
    const mysqlVersion = mysqlSelect.value;
    await postForm('create_instance', { folderPath, phpVersion, mysqlVersion });
    form.reset();
    refreshInstances();
  } catch (err) {
    startError.textContent = err.message;
  } finally {
    startBtn.disabled = false;
  }
});

function init() {
  refreshImages();
  refreshInstances();
  setInterval(refreshImages, 3000);
  setInterval(refreshInstances, 3000);
}

init();
