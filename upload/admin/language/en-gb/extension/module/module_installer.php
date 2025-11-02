<?php
// Heading
$_['heading_title']    = 'Module Installer (CLI)';

// Text
$_['text_extension']   = 'Extensions';
$_['text_success']     = 'Success: You have modified Module Installer module!';
$_['text_edit']        = 'Edit Module Installer Module';
$_['text_enabled']     = 'Enabled';
$_['text_disabled']    = 'Disabled';
$_['text_home']        = 'Home';
$_['text_success_install'] = 'Module successfully installed!';
$_['text_success_uninstall'] = 'Module successfully uninstalled!';

// Entry
$_['entry_status']     = 'Status';
$_['entry_github_repo'] = 'GitHub Repository';
$_['entry_github_repo_help'] = 'Format: owner/repo (e.g., telecart-labs/oc_module_installer)';
$_['entry_github_token'] = 'GitHub API Token';
$_['entry_github_token_help'] = 'Personal Access Token (classic) with "repo" scope for private repositories or "public_repo" for public ones. Create token at GitHub → Settings → Developer settings → Personal access tokens → Tokens (classic). For private repositories, "repo" scope (Full control of private repositories) is required. NOT logged.';
$_['entry_github_branch'] = 'Branch to check';
$_['entry_github_branch_help'] = 'Branch name to check commits (e.g., main, master, develop)';
$_['entry_artifact_name'] = 'Artifact name';
$_['entry_artifact_name_help'] = 'Exact name of artifact ZIP file to download (e.g., oc_telegram_shop.ocmod.zip)';
$_['entry_deploy_secret_key'] = 'Secret Key (Auto Deploy)';
$_['entry_deploy_secret_key_help'] = 'Secret key for accessing auto-deploy endpoint. Use this key in URL for automatic deployment from GitHub.';
$_['entry_last_deployed_sha'] = 'Last deployed commit SHA';
$_['entry_last_deploy_log'] = 'Last deployment log';

// Button
$_['button_save']      = 'Save';
$_['button_cancel']    = 'Cancel';

// Error
$_['error_permission'] = 'Warning: You do not have permission to modify Module Installer module!';

// Info
$_['info_cli_path']    = 'CLI Script Path:';
$_['info_cli_usage']   = 'CLI Usage:';
$_['info_cli_exists']  = 'CLI script found';
$_['info_cli_not_found'] = 'CLI script not found';
$_['info_description'] = '<p>This module provides CLI interface for installing OpenCart modules from zip archives.</p><p>Use the following command to install a module:</p><pre>php cli.php install-module /path/to/module.zip [--overwrite] [--verbose]</pre><p>Flags:</p><ul><li><strong>--overwrite</strong> - overwrite existing files</li><li><strong>--verbose</strong> - output verbose log</li></ul>';
$_['info_deploy_url'] = 'Auto Deploy URL:';
$_['info_deploy_description'] = '<p>This module supports automatic deployment from GitHub. Send GET or POST request to the URL above to trigger deployment.</p><p>Examples:</p><ul><li>Normal deployment: <code>curl "DEPLOY_URL"</code></li><li>Force deployment (even if SHA unchanged): <code>curl "DEPLOY_URL&force=1"</code></li></ul><p>The endpoint will check the latest commit SHA and download artifact if a new commit is found or force=1 is used.</p>';

