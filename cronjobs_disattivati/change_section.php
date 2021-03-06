<?php

eZExtension::activateExtensions();

$cli = eZCLI::instance();
$cli->setUseStyles(true);
$cli->setIsQuiet($isQuiet);

$user_ini = eZINI::instance('ocmaintenance.ini');
$CronjobUser = $user_ini->variable('UserSettings', 'CronjobUser');
/** @var eZUser $user */
$user = eZUser::fetchByName($CronjobUser);
if ($user) {
    eZUser::setCurrentlyLoggedInUser($user, $user->attribute('contentobject_id'));
    $cli->output("Eseguo lo script da utente {$user->attribute( 'contentobject' )->attribute( 'name' )}");
} else {
    throw new InvalidArgumentException("Non esiste un utente con nome utente $CronjobUser");
}


// lettura dei file INI
$ini = eZINI::instance('openpa.ini');
$Classes = $ini->variable('ChangeSection', 'ClassList');
$rootNodeIDList = $ini->variable('ChangeSection', 'RootNodeList');
$DataTime = $ini->variable('ChangeSection', 'DataTime');
$SectionIDs = $ini->variable('ChangeSection', 'ToSection');
$ScadenzaSecondi = $ini->hasVariable('ChangeSection', 'ScadeDopoTotSecondi') ? $ini->variable('ChangeSection',
    'ScadeDopoTotSecondi') : 0;
$UsaValore = $ini->hasVariable('ChangeSection', 'UsaValore') ? $ini->variable('ChangeSection', 'UsaValore') : false;
$Ignora = $ini->hasVariable('ChangeSection', 'Ignora') ? $ini->variable('ChangeSection', 'Ignora') : false;
$ScadenzaDefault = $ini->hasVariable('ChangeSection', 'ScadeDopoTotSecondiDefault') ? $ini->variable('ChangeSection',
    'ScadeDopoTotSecondiDefault') : 9262300400;
$currrentDate = time();

$countClasses = count($rootNodeIDList);
$i = 0;
$clones = array();

foreach ($rootNodeIDList as $class => $nodeID) {
    $i++;
    $rootNode = eZContentObjectTreeNode::fetch($nodeID);

    $cli->output($i . '/' . $countClasses . ' - classe: ' . $class . ', subtree: ' . $rootNode->attribute('node_id') . ', ',
        false);

    $usaValore = false;
    if (isset( $UsaValore[$class] )) {
        if ($UsaValore[$class] == 'maggiore' || $UsaValore[$class] == 'minore') {
            $usaValore = $UsaValore[$class];
        } else {
            throw new Exception("Valore UsaValore errato: " . $UsaValore[$class] . ". Valori ammessi: maggiore minore");
        }
    }

    $ignora = false;
    if (isset( $Ignora[$class] )) {
        if ($Ignora[$class] == 'attributo' || $Ignora[$class] == 'secondi') {
            $ignora = $Ignora[$class];
        } else {
            throw new Exception("Valore Ignora errato: " . $Ignora[$class] . ". Valori ammessi: attributo secondi");
        }
    }

    if (isset( $ScadenzaSecondi[$class] )) {
        $scadeDopoTotSecondi = $ScadenzaSecondi[$class];
    } else {
        $scadeDopoTotSecondi = $ScadenzaDefault;
    }

    $secondiLeggibile = intval($scadeDopoTotSecondi / 60 / 60 / 24 / 365);

    $unpublishDateAttribute = $DataTime[$class];

    $cli->output("attributo: $unpublishDateAttribute, secondi $scadeDopoTotSecondi ($secondiLeggibile anni)");
    if ($usaValore) {
        $cli->output("Usa il valore $usaValore");
    }
    if ($ignora) {
        $cli->output("Ignora $ignora");
    }

    if (!$unpublishDateAttribute) {
        $cli->error('Attributo non trovato');
        continue;
    }

    $NodeArray = $rootNode->subTree(array(
            'ClassFilterType' => 'include',
            'ClassFilterArray' => array($class),
            'LoadDataMap' => false,
            'Limitation' => array(),
            'AttributeFilter' => array(array('section', '!=', $SectionIDs[$class]))
        )
    );
    $count = count($NodeArray);
    $output = new ezcConsoleOutput();
    $progressBarOptions = array('emptyChar' => ' ', 'barChar' => '=');
    if ($isQuiet) {
        $progressBarOptions['minVerbosity'] = 10;
    }
    $progressBar = new ezcConsoleProgressbar($output, intval($count), $progressBarOptions);
    $progressBar->start();

    foreach ($NodeArray as $Node) {
        $progressBar->advance();
        $Object = $Node->attribute('object');

        // Debug su un nodo
        //if ( $Node->attribute( 'node_id' ) != 668195 ){continue;}
        //else
        //{
        //    var_dump( OscuraAttiHandler::isPrivacyClonedObject( $Object ) );
        //    var_dump( $Object->attribute( 'remote_id' ) );
        //    die();
        //
        //}

        $attributes = $Object->fetchAttributesByIdentifier(array($unpublishDateAttribute));
        $dateAttribute = array_shift($attributes);

        $date = $dateAttribute->content();
        $AttributeRetractDate = $date->attribute('timestamp');
        $IniRetractDate = $Object->attribute('published') + $scadeDopoTotSecondi;

        if ($AttributeRetractDate > 0) {
            $ObjectRetractDate = $AttributeRetractDate;
        } else {
            $ObjectRetractDate = $IniRetractDate;
        }

        if (!$ignora) {
            if ($usaValore && $usaValore == 'maggiore') {
                if ($AttributeRetractDate > $IniRetractDate) {
                    $ObjectRetractDate = $AttributeRetractDate;
                } else {
                    $ObjectRetractDate = $IniRetractDate;
                }
            }

            if ($usaValore && $usaValore == 'minore') {
                if ($AttributeRetractDate < $IniRetractDate) {
                    $ObjectRetractDate = $AttributeRetractDate;
                } else {
                    $ObjectRetractDate = $IniRetractDate;
                }
            }
        } elseif ($ignora == 'attributo') {
            $ObjectRetractDate = $IniRetractDate;
        } elseif ($ignora == 'secondi') {
            $ObjectRetractDate = $AttributeRetractDate;
        }

        if (is_null($dateAttribute)) {
            $cli->error('Attributo non trovato');
            continue;
        }

        if ($ObjectRetractDate > 0 && $ObjectRetractDate < $currrentDate) {
            $isClone = false;
            if (OscuraAttiHandler::isPrivacyClonedObject($Object)) {
                $clones[$class][] = $Object->attribute('main_node_id');
                $isClone = true;
            } elseif ($clone = OscuraAttiHandler::hasPrivacyClonedObject($Object)) {
                $clones[$class][] = $clone->attribute('main_node_id');
            }

            if (!$isClone) {
                if (eZOperationHandler::operationIsAvailable('content_updatesection')) {
                    $operationResult = eZOperationHandler::execute('content',
                        'updatesection',
                        array(
                            'node_id' => $Node->attribute('node_id'),
                            'selected_section_id' => $SectionIDs[$class]
                        ),
                        null,
                        true);

                } else {
                    eZContentOperationCollection::updateSection($Node->attribute('node_id'), $SectionIDs[$class]);
                }

                eZContentCacheManager::clearContentCacheIfNeeded($Object->attribute('id'));
            }
            eZContentObject::clearCache($Object->attribute('id'));
            $Object->resetDataMap();
        }
    }

    $progressBar->finish();
    $memoryMax = memory_get_peak_usage(); // Result is in bytes
    $memoryMax = round($memoryMax / 1024 / 1024, 2); // Convert in Megabytes
    $cli->output(' Memoria usata: ' . $memoryMax . 'M');
}

if (count($clones) > 0) {
    $cli->output();

    foreach ($clones as $class => $cloneNodesID) {
        foreach ($cloneNodesID as $cloneNodeID) {
            eZContentObjectTreeNode::removeSubtrees(array($cloneNodeID), true);
            $memoryMax = memory_get_peak_usage(); // Result is in bytes
            $memoryMax = round($memoryMax / 1024 / 1024, 2); // Convert in Megabytes
            $cli->output("Sposto nel cestino l'oggetto $class clone #$cloneNodeID (" . $memoryMax . 'M)');
        }
    }

}
