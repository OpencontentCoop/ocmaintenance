<?php



// Check for extension
//include_once( 'lib/ezutils/classes/ezextension.php' );
require_once( 'kernel/common/ezincludefunctions.php' );
eZExtension::activateExtensions();
// Extension check end

$cli = eZCLI::instance();
$cli->setUseStyles( true );

$cli->output( $cli->stylize( 'cyan', 'Leggo classi e attributi con le date di riferimento... ' ), false );
$user_ini = eZINI::instance( 'ocmaintenance.ini' );
$CronjobUser = $user_ini->variable( 'UserSettings', 'CronjobUser' );
$CronjobUserP = $user_ini->variable( 'UserSettings', 'CronjobUserP' );
$cli->output( $cli->stylize( 'cyan', "Lette\n" ), false );

// autentication as editor or administrator
$user = eZUser::loginUser( $CronjobUser, $CronjobUserP );
// check the login user
$logged_user = eZUser::currentUser();
$cli->output( $cli->stylize( 'red', "Si sta eseguendo l'agente con l'utente ".$logged_user->attribute( 'name' )."\n" ), false );


// lettura dei file INI
$ini = eZINI::instance( 'content.ini' );
$Classes =          $ini->variable( 'ChangeSection','ClassList' );
$rootNodeIDList =   $ini->variable( 'ChangeSection','RootNodeList' );
$DataTime =         $ini->variable( 'ChangeSection','DataTime' );
$SectionIDs =       $ini->variable( 'ChangeSection','ToSection' );
$ScadenzaSecondi =  $ini->variable( 'ChangeSection','ScadeDopoTotSecondi' );
$currrentDate = time();


foreach( $rootNodeIDList as $class => $nodeID )
{
    $rootNode = eZContentObjectTreeNode::fetch( $nodeID );
	// TODO: sostituire la fetch SQL con fetch solr per problemi di performance
	// controllare automaticamente (dalla definizione della classe) se le date sono indicizzate in solr, altrimenti restituire errore

	$articleNodeArray = $rootNode->subTree( array( 'ClassFilterType' => 'include',
                                                   'ClassFilterArray' => array( $class ),
                                                   'AttributeFilter' => array( array( 'section', '!=', $SectionIDs[$class] ) ) ) );

    foreach ( $articleNodeArray as $articleNode )
    {
        $article = $articleNode->attribute( 'object' );
        $dataMap = $article->attribute( 'data_map' );
	
        $unpublish_date = $DataTime[$article->ClassIdentifier];
        $ScadeDopoTotSecondi = $ScadenzaSecondi[$class];
        
        if (!$ScadeDopoTotSecondi>0) $ScadeDopoTotSecondi=9262300400;

        $dateAttribute = $dataMap[$unpublish_date];

        $date = $dateAttribute->content();
    
        $articleRetractDate = $date->attribute( 'timestamp' );

        if ( $articleRetractDate > 0 )
        {
        	$articleRetractDate = $date->attribute( 'timestamp' );
        }
        else
        {
            $articleRetractDate = $articleNode->ContentObject->Published + $ScadeDopoTotSecondi;
        }

        if ( is_null( $dateAttribute ) )
        {
            continue;
        }

        $cli->output( $cli->stylize( 'cyan', "Leggo l'oggetto ".$articleNode->attribute( 'id' ) . ": " . $articleNode->attribute('name'). "\n" ), false );
        
        if ( $articleRetractDate > 0  && $articleRetractDate < $currrentDate )
        {
            // Clean up content cache
            //include_once( 'kernel/classes/ezcontentcachemanager.php' );
            eZContentCacheManager::clearContentCacheIfNeeded( $article->attribute( 'id' ) );

            //$article->removeThis( $articleNode->attribute( 'node_id' ) );
            //cambia sezione
            //eZContentObjectTreeNode::assignSectionToSubTree( $articleNode->NodeID, $SectionIDs[$class] );
            if ( eZOperationHandler::operationIsAvailable( 'content_updatesection' ) )
            {
                $operationResult = eZOperationHandler::execute( 'content',
                                                                'updatesection',
                                                                array( 'node_id'             => $articleNode->NodeID,
                                                                       'selected_section_id' => $SectionIDs[$class] ),
                                                                null,
                                                                true );
            
            }
            else
            {
                eZContentOperationCollection::updateSection( $articleNode->NodeID, $SectionIDs[$class] );
            }
            
            $cli->output( $cli->stylize( 'cyan', "...Modificata sezione a ".$articleNode->attribute( 'id' ) . ": " . $articleNode->attribute('name'). "\n" ), false );
        }
  	
    }
}


?>
