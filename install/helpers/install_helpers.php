<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2024 Poweradmin Development Team
 *
 *  This program is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation, either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License
 *  along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

use Poweradmin\Application\Service\DatabaseService;
use Poweradmin\Application\Service\UserAuthenticationService;
use Poweradmin\Infrastructure\Database\PDODatabaseConnection;
use Poweradmin\AppConfiguration;
use PoweradminInstall\InstallationSteps;

require_once 'database_helpers.php';

function checkConfigFile($current_step, $local_config_file, $twig): void
{
    if (file_exists($local_config_file)) {
        if ($current_step == InstallationSteps::STEP_INSTALLATION_COMPLETE) {
            return; // Allow last step to be shown
        } else {
            echo "<p class='alert alert-danger'>" . _('There is already a configuration file in place, so the installation will be skipped.') . "</p>";
            echo $twig->render('footer.html');
            exit;
        }
    }
}

function renderTemplate($twig, $templateName, $data): void
{
    $data['next_step'] = filter_var($data['current_step'], FILTER_VALIDATE_INT) ?: 0;
    $data['next_step'] += 1;
    $data['file_version'] = time();
    echo $twig->render($templateName, $data);
}

function step1ChooseLanguage($twig, $current_step): void
{
    renderTemplate($twig, 'step1.html.twig', array(
        'current_step' => $current_step
    ));
}

function step2GettingReady($twig, $current_step, $language): void
{
    renderTemplate($twig, 'step2.html.twig', array(
        'current_step' => $current_step,
        'language' => htmlspecialchars($language)
    ));
}

function step3ConfiguringDatabase($twig, $current_step, $language): void
{
    renderTemplate($twig, 'step3.html.twig', array(
        'current_step' => $current_step,
        'language' => htmlspecialchars($language)
    ));
}

function step4SetupAccountAndNameServers($twig, $current_step, $default_config_file): void {
    echo "<p class='alert alert-secondary'>" . _('Updating database...') . " ";

    $credentials = [
        'db_user' => $_POST['user'],
        'db_pass' => $_POST['pass'],
        'db_host' => $_POST['host'],
        'db_port' => $_POST['dbport'],
        'db_name' => $_POST['name'],
        'db_charset' => $_POST['charset'],
        'db_collation' => $_POST['collation'],
        'db_type' => $_POST['type'],
    ];

    if ($credentials['db_type'] == 'sqlite') {
        $credentials['db_file'] = $credentials['db_name'];
    }

    foreach ($credentials as $key => $value) {
        $value = strip_tags(trim($value));
        $credentials[$key] = $value;
    }

    $pa_pass = $_POST['pa_pass'];

    $databaseConnection = new PDODatabaseConnection();
    $databaseService = new DatabaseService($databaseConnection);
    $db = $databaseService->connect($credentials);

    updateDatabase($db, $credentials);

    createAdministratorUser($db, $pa_pass, $default_config_file);

    echo _('done!') . "</p>";

    if ($credentials['db_type'] == 'sqlite') {
        $current_step = InstallationSteps::STEP_CREATE_LIMITED_RIGHTS_USER;
    }

    renderTemplate($twig, 'step4.html.twig', array_merge([
        'current_step' => $current_step,
        'language' => htmlspecialchars($_POST['language']),
        'pa_pass' => htmlspecialchars($pa_pass),
    ], $credentials));
}

function step5CreateLimitedRightsUser($twig, $current_step, $language): void
{
    $current_step++;

    $credentials = [
        'db_user' => $_POST['db_user'],
        'db_pass' => $_POST['db_pass'],
        'db_host' => $_POST['db_host'],
        'db_port' => $_POST['db_port'],
        'db_name' => $_POST['db_name'],
        'db_charset' => $_POST['db_charset'],
        'db_collation' => $_POST['db_collation'],
        'db_type' => $_POST['db_type'],
    ];

    if ($credentials['db_type'] == 'sqlite') {
        $credentials['db_file'] = $credentials['db_name'];
    } else {
        $credentials['pa_db_user'] = $_POST['pa_db_user'];
        $credentials['pa_db_pass'] = $_POST['pa_db_pass'];
    }

    $pa_pass = $_POST['pa_pass'];
    $hostmaster = $_POST['dns_hostmaster'];
    $dns_ns1 = $_POST['dns_ns1'];
    $dns_ns2 = $_POST['dns_ns2'];

    $databaseConnection = new PDODatabaseConnection();
    $databaseService = new DatabaseService($databaseConnection);
    $db = $databaseService->connect($credentials);

    $instructions = generateDatabaseUserInstructions($db, $credentials);

    renderTemplate($twig, 'step5.html.twig', array(
        'current_step' => $current_step,
        'language' => htmlspecialchars($language),
        'db_host' => htmlspecialchars($credentials['db_host']),
        'db_name' => htmlspecialchars($credentials['db_name']),
        'db_port' => htmlspecialchars($credentials['db_port']),
        'db_type' => htmlspecialchars($credentials['db_type']),
        'db_user' => htmlspecialchars($credentials['db_user']),
        'db_pass' => htmlspecialchars($credentials['db_pass']),
        'db_charset' => htmlspecialchars($credentials['db_charset']),
        'pa_db_user' => isset($credentials['pa_db_user']) ? htmlspecialchars($credentials['pa_db_user']) : '',
        'pa_db_pass' => isset($credentials['pa_db_pass']) ? htmlspecialchars($credentials['pa_db_pass']) : '',
        'pa_pass' => htmlspecialchars($pa_pass),
        'dns_hostmaster' => htmlspecialchars($hostmaster),
        'dns_ns1' => htmlspecialchars($dns_ns1),
        'dns_ns2' => htmlspecialchars($dns_ns2),
        'instructions' => $instructions
    ));
}

function step6CreateConfigurationFile($twig, $current_step, $language, $default_config_file, $local_config_file): void
{
    // No need to set database port if it's standard port for that db
    $db_port = ($_POST['db_type'] == 'mysql' && $_POST['db_port'] != 3306)
    || ($_POST['db_type'] == 'pgsql' && $_POST['db_port'] != 5432) ? $_POST['db_port'] : '';

    // For SQLite we should provide path to db file
    $db_file = $_POST['db_type'] == 'sqlite' ? htmlspecialchars($_POST['db_name']) : '';

    $config = new AppConfiguration($default_config_file);

    $dns_hostmaster = $_POST['dns_hostmaster'];
    $dns_ns1 = $_POST['dns_ns1'];
    $dns_ns2 = $_POST['dns_ns2'];
    $dns_ns3 = ''; // $_POST['dns_ns3'];
    $dns_ns4 = ''; // $_POST['dns_ns4'];
    $db_host = $_POST['db_host'];
    $db_user = $_POST['pa_db_user'] ?? '';
    $db_pass = $_POST['pa_db_pass'] ?? '';
    $db_name = $_POST['db_name'];
    $db_type = $_POST['db_type'];
    $db_charset = $_POST['db_charset'];
    $pa_pass = $_POST['pa_pass'];

    $userAuthService = new UserAuthenticationService(
        $config->get('password_encryption'),
        $config->get('password_encryption_cost')
    );

    renderTemplate($twig, 'step6.html.twig', array(
        'current_step' => (int)htmlspecialchars($current_step),
        'language' => htmlspecialchars($language),
        'local_config_file' => $local_config_file,
        'session_key' => $userAuthService->generateSalt(SESSION_KEY_LENGTH),
        'iface_lang' => htmlspecialchars($language),
        'dns_hostmaster' => htmlspecialchars($dns_hostmaster),
        'dns_ns1' => htmlspecialchars($dns_ns1),
        'dns_ns2' => htmlspecialchars($dns_ns2),
        'db_host' => htmlspecialchars($db_host),
        'db_user' => htmlspecialchars($db_user),
        'db_pass' => htmlspecialchars($db_pass),
        'db_name' => htmlspecialchars($db_name),
        'db_file' => $db_file,
        'db_type' => htmlspecialchars($db_type),
        'db_port' => htmlspecialchars($db_port),
        'db_charset' => htmlspecialchars($db_charset),
        'pa_pass' => htmlspecialchars($pa_pass)
    ));
}

function step7InstallationComplete($twig): void
{
    renderTemplate($twig, 'step7.html.twig', array(
        'current_step' => InstallationSteps::STEP_INSTALLATION_COMPLETE,
    ));
}
