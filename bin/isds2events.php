#!/usr/bin/php -f
<?php

declare(strict_types=1);

/**
 * This file is part of the AbraFlexi-ISDS package
 *
 * https://github.com/VitexSoftware/abraflexi-isds/
 *
 * (c) Vítězslav Dvořák <https://vitexsoftware.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace AbraFlexiIsds;

use Defr\CzechDataBox\DataBox;
use Ease\Locale;
use Ease\Shared;

require_once __DIR__.'/../vendor/autoload.php';

$options = getopt('o::e::', ['output::', 'environment::']);

Shared::init(
    [
        'ABRAFLEXI_URL',
        'ABRAFLEXI_LOGIN',
        'ABRAFLEXI_PASSWORD',
        'ABRAFLEXI_COMPANY',
    ],
    \array_key_exists('environment', $options) ? $options['environment'] : (\array_key_exists('e', $options) ? $options['e'] : ''),
);

$destination = \array_key_exists('output', $options) ? $options['output'] : (\array_key_exists('o', $options) ? $options['o'] : Shared::cfg('RESULT_FILE', 'php://stdout'));
$localer = new Locale('cs_CZ', '../i18n', 'abraflexi-isds');

// Data Box credentials come from the "Datovka" MultiFlexi credential
// (see vitexsoftware/multiflexi-datovka): LOGIN+PASSWORD or CERT_FILE+PASSWORD.
$certFile = Shared::cfg('CERT_FILE', '');
$production = strtolower(Shared::cfg('PRODUCTION', 'false')) === 'true';

$dataBox = new DataBox(Shared::cfg('ISDS_CACHE_DIR', sys_get_temp_dir().'/abraflexi-isds'));

if ($certFile !== '') {
    $dataBox->loginWithCertificateAndPassword($certFile, Shared::cfg('PASSWORD'), $production);
} else {
    $dataBox->loginWithUsernameAndPassword(Shared::cfg('LOGIN'), Shared::cfg('PASSWORD'), $production);
}

$eventor = new Event();
$report = $eventor->createEvents($dataBox);

$written = file_put_contents($destination, json_encode($report, Shared::cfg('APP_DEBUG', false) ? \JSON_PRETTY_PRINT : 0));
$eventor->addStatusMessage(sprintf(_('Saving result to %s'), $destination), $written ? 'success' : 'error');

exit($report['failed'] > 0 ? 1 : 0);
