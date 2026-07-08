<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>QloApps Version Matrix</title>
<link rel="stylesheet" href="assets/style.css" />
</head>
<body>
<header>
  <h1>QloApps Version Matrix</h1>
  <p class="sub">Run any QloApps folder against PHP 5.6 / 7.4 / 8.1 / 8.4 &times; MySQL 5.7 / 8.0 in isolated containers.</p>
</header>

<main>
  <section id="images-section">
    <h2>1. PHP images</h2>
    <p class="hint">Build the PHP image for a version once — reused by every instance you start with it.</p>
    <div id="image-grid" class="image-grid">
      {foreach from=$images item=img}
      <div class="image-card" data-version="{$img.version}">
        <div class="version">PHP {$img.version}</div>
        <div class="status {if $img.built}built{/if}">
          {if $img.building}Building...{elseif $img.built}Built{else}Not built{/if}
        </div>
        <button class="secondary" data-build="{$img.version}" {if $img.building}disabled{/if}>
          {if $img.built}Rebuild{else}Build{/if}
        </button>
      </div>
      {/foreach}
    </div>
    <pre id="build-log" class="build-log hidden"></pre>
  </section>

  <section id="new-instance-section">
    <h2>2. Start an instance</h2>
    <form id="new-instance-form">
      <div class="field">
        <label for="folderPath">QloApps folder path</label>
        <input type="text" id="folderPath" name="folderPath" placeholder="/home/sumit/www/html/QloApps-develop" required />
      </div>
      <div class="field-row">
        <div class="field">
          <label for="phpVersion">PHP version</label>
          <select id="phpVersion" name="phpVersion">
            {foreach from=$phpVersions item=v}
            <option value="{$v}">PHP {$v}</option>
            {/foreach}
          </select>
        </div>
        <div class="field">
          <label for="mysqlVersion">MySQL version</label>
          <select id="mysqlVersion" name="mysqlVersion">
            {foreach from=$mysqlVersions item=v}
            <option value="{$v}">MySQL {$v}</option>
            {/foreach}
          </select>
        </div>
      </div>
      <button type="submit" id="start-btn">Start instance</button>
      <span id="start-error" class="error"></span>
    </form>
  </section>

  <section id="instances-section">
    <h2>3. Running instances</h2>
    <table id="instances-table" class="{if count($instances) == 0}hidden{/if}">
      <thead>
        <tr>
          <th>Label</th>
          <th>PHP</th>
          <th>MySQL</th>
          <th>Folder</th>
          <th>Status</th>
          <th>Open</th>
          <th>DB (host)</th>
          <th>Logs</th>
          <th></th>
        </tr>
      </thead>
      <tbody id="instances-body">
        {foreach from=$instances item=i}
        <tr>
          <td>{$i.label}</td>
          <td>{$i.phpVersion}</td>
          <td>{$i.mysqlVersion}</td>
          <td class="folder" title="{$i.folderPath}">{$i.folderPath}</td>
          <td><span class="badge {$i.status}">{$i.status}</span></td>
          <td><a href="http://localhost:{$i.phpPort}/" target="_blank">localhost:{$i.phpPort}</a></td>
          <td><a href="/adminer.php?server=127.0.0.1:{$i.dbPort}&amp;username=qloapps&amp;db=qloapps" target="_blank" rel="noopener">localhost:{$i.dbPort}</a></td>
          <td>
            <button class="secondary" data-logs="{$i.id}" data-container="php">PHP log</button>
            <button class="secondary" data-logs="{$i.id}" data-container="db">DB log</button>
          </td>
          <td><button class="danger" data-stop="{$i.id}">Stop</button></td>
        </tr>
        {/foreach}
      </tbody>
    </table>
    <p id="no-instances" class="hint {if count($instances) > 0}hidden{/if}">No instances running yet.</p>
  </section>
</main>

<div id="logs-modal" class="modal hidden">
  <div class="modal-content">
    <div class="modal-header">
      <span id="logs-title"></span>
      <button id="logs-close">&times;</button>
    </div>
    <pre id="logs-body"></pre>
  </div>
</div>

<script src="assets/app.js"></script>
</body>
</html>
