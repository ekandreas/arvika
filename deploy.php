<?php
namespace Deployer;

require 'recipe/composer.php';

set('repository', 'https://github.com/ekandreas/arvika.git');
set('git_tty', true);
set('shared_files', ['.env','web/.htaccess']);
set('shared_dirs', ['web/app/uploads']);

localhost()
    ->stage('development');

host('swpu.se')
    ->port(22)
    ->set('deploy_path', '~/sites/arvika.swpu.se')
    ->user('swpuse')
    ->set('branch', 'master')
    ->stage('production')
    ->identityFile('~/.ssh/id_rsa');

// Tasks
task('wp-init', function () {
    $deploy_path = has('deploy_path') ? get('deploy_path') : null;
    $path = $deploy_path ? "cd {$deploy_path}/current && " : "";

    $host = run("{$path}wp config get --constant=WP_HOME");
    $password = uniqid('pass');

    run("{$path}wp core install --url={$host} --title=RootsBedrock --admin_user=root --admin_password={$password} --admin_email=root@example.se");

    writeln("WordPress installed with user 'root' and password '{$password}'");
})->desc('Initialize your local or production setup from scratch');

task('wp-cleanup', function () {
    $envFile = run("cd {{deploy_path}}/current && cat .env");

    if (!$envFile) {
        return;
    }

    $actions = [
        "wp language core install sv_SE",
        "wp language core update",
        "wp language core activate sv_SE",
        "wp rewrite structure '/%postname%'",
        "wp rewrite flush",
        "wp option update timezone_string \"Europe/Stockholm\"",
        "wp option update date_format \"Y-m-d\"",
        "wp option update time_format \"H:i\"",
        "php -r \"opcache_reset();\"",
        "wp cache flush",
    ];

    foreach ($actions as $action) {
        writeln($action);
        run("cd {{deploy_path}}/current && $action", [
            "timeout" => 999,
        ]);
    }
})->desc('After deploy, make WordPress clean for us');
after('deploy', 'wp-cleanup');

task('pull', function () {
    $host = Task\Context::get()->getHost();
    $user = $host->getUser();
    $hostname = $host->getHostname();

    $url = parse_url(run("cd {{deploy_path}}/current && wp config get --constant=WP_HOME"), PHP_URL_HOST);
    $localUrl = parse_url(runLocally("wp config get --constant=WP_HOME"), PHP_URL_HOST);

    $actions = [
        "ssh {$user}@{$hostname} 'cd {{deploy_path}}/current && wp db export - | gzip' > db.sql.gz",
        "gzip -df db.sql.gz",
        "wp language core install sv_SE",
        "wp db import db.sql",
        "rm -f db.sql",
        "wp search-replace '{$url}' '{$localUrl}' --all-tables",
        "rsync --exclude .cache -re ssh " .
            "{$user}@{$hostname}:{{deploy_path}}/shared/web/app/uploads web/app",
        "wp rewrite flush",
        "wp cache flush",
        "wp theme update --all"
    ];

    foreach ($actions as $action) {
        writeln("{$action}");
        writeln(runLocally($action, ['timeout' => 999]));
    }
})->desc('Get the production setup to your local dev env');
