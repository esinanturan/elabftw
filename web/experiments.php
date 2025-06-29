<?php

/**
 * @author Nicolas CARPi <nico-git@deltablot.email>
 * @copyright 2012 Nicolas CARPi
 * @see https://www.elabftw.net Official website
 * @license AGPL-3.0
 * @package elabftw
 */

declare(strict_types=1);

namespace Elabftw\Elabftw;

use Elabftw\Controllers\ExperimentsController;
use Elabftw\Enums\EntityType;
use Elabftw\Exceptions\DatabaseErrorException;
use Elabftw\Exceptions\IllegalActionException;
use Elabftw\Exceptions\ImproperActionException;
use Elabftw\Models\Experiments;
use Elabftw\Services\AccessKeyHelper;
use Elabftw\Services\Filter;
use Exception;
use Symfony\Component\HttpFoundation\Response;

/**
 * Entry point for all experiment stuff
 */
require_once 'app/init.inc.php';

// default response is error page with general error message
$Response = new Response();
$Response->prepare($Request);
$template = 'error.html';

try {
    $id = Filter::intOrNull($Request->query->getInt('id'));
    $bypassReadPermission = false;
    // if we have an access_key we get the id from that
    if ($App->Request->query->has('access_key') && $App->Request->query->get('access_key')) {
        // for that we fetch the id not from the id param but from the access_key, so we will get a valid id that corresponds to an entity
        // with this access_key
        $id = new AccessKeyHelper(EntityType::Experiments)->getIdFromAccessKey($App->Request->query->getString('access_key'));
        if ($id > 0) {
            $bypassReadPermission = true;
        }
    }
    $Controller = new ExperimentsController($App, new Experiments($App->Users, $id, bypassReadPermission: $bypassReadPermission));
    $Response = $Controller->getResponse();
} catch (IllegalActionException $e) {
    // log notice and show message
    $App->Log->notice('', array(array('userid' => $App->Session->get('userid')), array('IllegalAction', $e)));
    $renderArr = array('error' => Tools::error(true));
    $Response->setContent($App->render($template, $renderArr));
} catch (ImproperActionException $e) {
    // show message to user
    $renderArr = array('error' => $e->getMessage());
    $Response->setContent($App->render($template, $renderArr));
} catch (DatabaseErrorException $e) {
    // log error and show message
    $App->Log->error('', array(array('userid' => $App->Session->get('userid')), array('Error', $e)));
    $renderArr = array('error' => $e->getMessage());
    $Response->setContent($App->render($template, $renderArr));
} catch (Exception $e) {
    // log error and show general error message
    $App->Log->error('', array(array('userid' => $App->Session->get('userid')), array('Exception' => $e)));
    $renderArr = array('error' => Tools::error());
    $Response->setContent($App->render($template, $renderArr));
} finally {
    // autologout if there is elabid in view mode
    // so we don't stay logged in as anon
    if ($App->Request->query->has('elabid')
        && $App->Request->query->get('mode') === 'view'
        && !$App->Request->getSession()->has('is_auth')) {
        $App->Session->invalidate();
    }

    $Response->send();
}
