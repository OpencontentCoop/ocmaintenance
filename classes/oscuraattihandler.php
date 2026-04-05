<?php

class OscuraAttiHandler
{
    private $output, $currentFile, $currentFileError;
    
    const REMOTE_PREFIX = 'privacy_';
    
    const ATTI_POST = 1;
    const DELIBERE_ANTE = 2;
    const DETERMINE_ANTE = 3;
    
    const RUNASTEST = false;
    
    function __construct( $options = array() )
    {
        foreach ( $options as $key => $value )
        {
            $this->{$key} = $value;
        }
        $this->output = new ezcConsoleOutput();
    }
    
    private function getCSVFiles()
    {
        // @phpstan-ignore property.notFound
        $RepositoryNodeID = $this->ini->variable( 'OscuraAttiSettings', 'RepositoryNodeID' );        
        $CSVFileNodes = eZContentObjectTreeNode::subTreeByNodeID( array(
                                                                        'Depth' => 1,
                                                                        'DepthOperator' => 'le',
                                                                        'ClassFilterType' => 'include',
                                                                        'ClassFilterArray' => array( 'csv_oscura_oggetti' ),
                                                                        'AttributeFilter' => array( array( 'csv_oscura_oggetti/enqueue', '>', 0 ) )
                                                                        ),
                                                                 $RepositoryNodeID );
        
        // @phpstan-ignore property.notFound
        $this->cli->output( 'Ci sono ' . count( $CSVFileNodes ) . ' file da elaborare' );

// PER TESTARE UN FILE SINGOLO SCRIVI IL NODO DENTRO ALLA FECTH
//$CSVFileNodes = array( eZContentObjectTreeNode::fetch(667678) );

        $return = array();
        
        foreach( $CSVFileNodes as $CSVFileNode )
        {
            $CSVFileObject = $CSVFileNode->attribute( 'object' );
            $attributes = $CSVFileObject->fetchAttributesByIdentifier( array( 'file_csv', 'tipo_atto' ) );
            foreach ( $attributes as $attribute )
            {
                if ( $attribute->hasContent()
                    && $attribute->attribute( 'contentclass_attribute_identifier' ) == 'file_csv' )
                {
                    $info = $attribute->content()->storedFileInfo();                    
                }
                if ( $attribute->hasContent()
                    && $attribute->attribute( 'contentclass_attribute_identifier' ) == 'tipo_atto' )
                {
                    $fileType = $attribute->toString();                    
                }
            }
            // @phpstan-ignore variable.undefined
            $return[$info['filepath']] = array( 'object' => $CSVFileObject,
                                               // @phpstan-ignore variable.undefined
                                               'type' => $fileType,
                                               // @phpstan-ignore variable.undefined
                                               'fileName' => $info['original_filename'] );
        }
        return $return;
    }
    
    private function selectFileType( $fileType )
    {
        switch ( $fileType )
        {
            case 'Determine dirigenziali, delibere di Giunta e Consiglio o deliberazioni circoscrizionali esecutive DOPO il 15 maggio 2012':
                return self::ATTI_POST;
            break;
            
            case 'Delibere di Giunta e Consiglio o deliberazioni circoscrizionali esecutive PRIMA del 15 maggio 2012':
                return self::DELIBERE_ANTE;
            break;
        
            case 'Determine dirigenziali esecutive PRIMA del 15 maggio 2012':
                return self::DETERMINE_ANTE;
            break;
            
            default:
            return false;
        }
    }
    
    private function processTest( $results, $type, $row )
    {
        $attributesToModify = array( 'oggetto' => '' );
        // @phpstan-ignore property.notFound
        $attributesToChangeSections = $this->ini->variable( 'OscuraAttiSettings', 'ChangeSectionToAttributes' );
        
        // @phpstan-ignore property.notFound
        $IntranetSectionID = $this->ini->variable( 'OscuraAttiSettings', 'RestrictedSection' ); //29;
        // @phpstan-ignore property.notFound
        $ReteCivicaSectionID = $this->ini->variable( 'OscuraAttiSettings', 'OpenSection' ); //28;
        // @phpstan-ignore property.notFound
        $ApertoATuttiSectionID = $this->ini->variable( 'OscuraAttiSettings', 'SharedSection' ); //1;
        
        // @phpstan-ignore property.notFound
        $headers = $this->doc->rows->getHeaders();
        // @phpstan-ignore property.notFound
        $rawHeaders = $this->doc->rows->getRawHeaders();        
        // @phpstan-ignore property.notFound
        $iniHeaders = $this->ini->variable( 'OscuraAttiSettings', 'CSVHeaders_' . $type );
        // @phpstan-ignore property.notFound
        $iniOrganoCompetente = $this->ini->variable( 'OscuraAttiSettings', 'OrganoCompetente' );

        $tipoAttoHeader = $iniHeaders['tipoAtto'];
        $codiceCircoscrizioneHeader = $iniHeaders['codiceCircoscrizione'];
        $annoHeader = $iniHeaders['anno'];
        $numeroHeader = $iniHeaders['numero'];
        
        $modificaOggetto = false;
        $oscuraAllegati = false;
        if ( $type > self::ATTI_POST )
        {
            $oggettoModificatoHeader = $iniHeaders['oggettoModificato'];
            $oggettoDaModificareFlagHeader = $iniHeaders['flagModificaOggettoX'];
            $oscuraTestoAllegatiFlagHeader = $iniHeaders['flagOscuraTestoAllegatiX'];
            
            if ( isset( $row->{$oggettoDaModificareFlagHeader} )
                && strtolower( trim( $row->{$oggettoDaModificareFlagHeader} ) ) == 'x'
                )
            {
                $modificaOggetto = true;
                if ( isset( $row->{$oggettoModificatoHeader} ) )
                    $attributesToModify['oggetto'] = (string) $row->{$oggettoModificatoHeader};
                else
                    // @phpstan-ignore property.notFound
                    $attributesToModify['oggetto'] = $this->ini->variable( 'OscuraAttiSettings', 'OggettoStandard' );
            }
                        
            if ( isset( $row->{$oscuraTestoAllegatiFlagHeader} )
                && strtolower( trim( $row->{$oscuraTestoAllegatiFlagHeader} ) ) == 'x' )
            {
                $oscuraAllegati = true;
            }
        }
        elseif ( $type == self::ATTI_POST )
        {
            $oscuraAllegati = true;
        }
        
        foreach( $attributesToModify as $text )
        {
            if ( !$this->is_utf8( $text ) )
            {
                $this->error( "Impossibile modificare l'oggetto. Il charset del file e' corretto? Riga bloccata: " . $row );
                return;
            }
        }
        
        foreach ( $results as $result )
        {
            $originalContentNode = eZContentObjectTreeNode::fetch( $result->MainNodeID );
            $originalContentObject = eZContentObject::fetch( $result->ContentObjectID );
            $this->notice( ' * Elaboro object:#' . $originalContentObject->attribute( 'id' ) . ' node:#' . $originalContentObject->attribute( 'main_node_id' ), false, 'TEST' );
            $originalContentObjectRemoteID = $originalContentObject->attribute( 'remote_id' );
            $done = eZContentObject::fetchByRemoteID( self::REMOTE_PREFIX . $originalContentObjectRemoteID );

            if ( is_object( $originalContentObject )
                && $done === NULL
                && strpos( $originalContentObjectRemoteID, self::REMOTE_PREFIX ) === false ) 
            {
                
                $db = eZDB::instance();
                $originalSectionID = $originalContentObject->attribute( 'section_id' );
                
                // è in sezione aperta a tutti o retecivica? 
                if ( $originalSectionID == $ReteCivicaSectionID
                    || $originalSectionID == $ApertoATuttiSectionID )
                {
                
                    if ( $modificaOggetto && $oscuraAllegati )
                    {
                        // duplico l'oggetto
                        $this->notice( ' - duplico in', false, 'TEST' );
                        $this->notice( ' object:#TEST node:#TEST', false, 'TEST' );
                    }
                    
                    if ( $modificaOggetto )
                    {
                        $this->notice( ' - modifico oggetto', false, 'TEST' );
                    }
                    
                    if ( $oscuraAllegati )
                    {
                        foreach( $attributesToChangeSections as $attributeName )
                        {
                            $this->notice( ' - oscuro ' . $attributeName, false, 'TEST' );
                        }
                    }
                
                }
                else
                {
                    $this->notice( ' già oscurato in sezione ' . $originalSectionID, 'TEST' );
                }
            }
            elseif ( $done )
            {
                $this->notice( ' già clonato in object:#' . $done->attribute( 'id' ) . ' node:#' . $done->attribute( 'main_node_id' ), true, 'TEST' );        
            }
            else
            {
                $this->error( 'errore', true, 'TEST' );
            }
        }
        
        return true;
    }
    
    private function process( $row, $fileType )
    {                        
        $type = $this->selectFileType( $fileType );
        if ( $type === false )
        {
            $this->error( 'Tipo di atto non riconosciuto' . $fileType );
            return false;    
        }
                
        $attributesToModify = array( 'oggetto' => '' );
        // @phpstan-ignore property.notFound
        $attributesToChangeSections = $this->ini->variable( 'OscuraAttiSettings', 'ChangeSectionToAttributes' );
        
        // @phpstan-ignore property.notFound
        $IntranetSectionID = $this->ini->variable( 'OscuraAttiSettings', 'RestrictedSection' ); //29;
        // @phpstan-ignore property.notFound
        $ReteCivicaSectionID = $this->ini->variable( 'OscuraAttiSettings', 'OpenSection' ); //28;
        // @phpstan-ignore property.notFound
        $ApertoATuttiSectionID = $this->ini->variable( 'OscuraAttiSettings', 'SharedSection' ); //1;
        
        // @phpstan-ignore property.notFound
        $headers = $this->doc->rows->getHeaders();
        // @phpstan-ignore property.notFound
        $rawHeaders = $this->doc->rows->getRawHeaders();
        
        // @phpstan-ignore property.notFound
        $iniHeaders = $this->ini->variable( 'OscuraAttiSettings', 'CSVHeaders_' . $type );
        foreach ( $iniHeaders as $header )
        {
            if ( !isset( $row->{$header} ) )
            {
                $this->notice('');                
                $this->error( 'Gli headers del CSV non corrispondono ai valori del file di configurazione. Hai selezionato il tipo di atto corretto quando hai importato il file csv? Il file oscuraatti.ini contiene gli headers corretti?' );
                $this->error( 'Headers del file csv elaborato:' );
                // @phpstan-ignore property.notFound
                $this->error( var_export( $this->doc->rows->getHeaders(), 1 ) );
                $this->error( 'Headers che lo script si aspetta:' );
                $this->error( var_export( array_values( $iniHeaders ), 1 ) );
                $this->error( 'Header' . $header . ' non trovato' );
                return false;
            }
        }
        // @phpstan-ignore property.notFound
        $iniOrganoCompetente = $this->ini->variable( 'OscuraAttiSettings', 'OrganoCompetente' );

        $tipoAttoHeader = $iniHeaders['tipoAtto'];
        $codiceCircoscrizioneHeader = $iniHeaders['codiceCircoscrizione'];
        $annoHeader = $iniHeaders['anno'];
        $numeroHeader = $iniHeaders['numero'];
        
        $modificaOggetto = false;
        $oscuraAllegati = false;
        if ( $type > self::ATTI_POST )
        {
            $oggettoModificatoHeader = $iniHeaders['oggettoModificato'];
            $oggettoDaModificareFlagHeader = $iniHeaders['flagModificaOggettoX'];
            $oscuraTestoAllegatiFlagHeader = $iniHeaders['flagOscuraTestoAllegatiX'];
            
            if ( isset( $row->{$oggettoDaModificareFlagHeader} )
                && strtolower( trim( $row->{$oggettoDaModificareFlagHeader} ) ) == 'x'
                ) 
            {
                $modificaOggetto = true;
                if ( isset( $row->{$oggettoModificatoHeader} ) )
                    $attributesToModify['oggetto'] = (string) $row->{$oggettoModificatoHeader};
                else
                    // @phpstan-ignore property.notFound
                    $attributesToModify['oggetto'] = $this->ini->variable( 'OscuraAttiSettings', 'OggettoStandard' );
            }
            
            if ( isset( $row->{$oscuraTestoAllegatiFlagHeader} )
                && strtolower( trim( $row->{$oscuraTestoAllegatiFlagHeader} ) ) == 'x' )
            {
                $oscuraAllegati = true;
            }
            
        }
        elseif ( $type == self::ATTI_POST )
        {
            $oscuraAllegati = true;
        }
        
        foreach( $attributesToModify as $text )
        {
            if ( !$this->is_utf8( $text ) )
            {
                $this->error( "Impossibile modificare l'oggetto. Il charset del file e' corretto? Riga bloccata: " . $row );
                return;
            }
        }

        $filters = array();

        // ricavo l'oggetto
        $tipoAtto = strtolower( $row->{$tipoAttoHeader} );        
        if ( strpos( $tipoAtto, 'del' ) !== false )
        {
            $class = 'deliberazione';
            
            if ( strpos( $tipoAtto, 'cons' ) !== false )
            {
                $organoCompetente = $iniOrganoCompetente['consiglio-comunale']; // 1059; // Consiglio Comunale
            }
            elseif ( strpos( $tipoAtto, 'giunta' ) !== false )
            {
                $organoCompetente = $iniOrganoCompetente['giunta']; //1058; // Giunta Comunale
            }
            elseif ( strpos( $tipoAtto, 'circ' ) !== false )
            {
                if ( isset( $row->{$codiceCircoscrizioneHeader} ) )
                {
                    $organoCompetente = $iniOrganoCompetente['circoscrizione-' . $row->{$codiceCircoscrizioneHeader}];
                }
                
            }            
            // @phpstan-ignore variable.undefined
            $organoCompetente = explode( ';', $organoCompetente );
            if ( count( $organoCompetente ) > 1 )
            {
                $tmpFilters = array( 'or' );
                foreach( $organoCompetente as $oC )
                {
                    $tmpFilters[] = 'submeta_organo_competente___id_si:' . $oC;
                }
                $filters = array_merge( $filters, array( $tmpFilters ) );
            }
            else
            {
                $filters['submeta_organo_competente___id_si'] = $organoCompetente[0];
            }
        }
        else
        {
            switch ( $tipoAtto )
            {
                case 'determina':
                    $class = 'determinazione';
                    break;
                default:
                $class = trim( $tipoAtto );
                break;
            }
        }
        
        // @phpstan-ignore property.notFound
        $filtersPerClass = $this->ini->variable( 'OscuraAttiSettings', 'Filter_' . $class );
        
        $contentClass = eZContentClass::fetchByIdentifier( $class );
        if ( !$contentClass )
        {
            $this->error( 'Nessuna classe trovata per ' . $tipoAtto );
            return false;    
        }
        
        $filters[$filtersPerClass['numero']] = trim( $row->{$numeroHeader} );
        $filters[$filtersPerClass['anno']] = trim( $row->{$annoHeader} );
        $filters['contentclass_id'] = $contentClass->attribute( 'id' );

// PER TESTARE UNA SINGOLA RIGA DI CSV
//if ( $filters[$filtersPerClass['numero']] != '204' ) return;

        $solrSearch = new eZSolr();
        $query = '';
        $params = array(
                        'SearchSubTreeArray' => array( 2 ),
                        'Filter' => $filters
                        );
        $searchData = $solrSearch->search( $query, $params );

/* CODICE PER TESTARE LA RICERCA 
print_r($params);
var_dump($searchData['SearchCount']);
foreach( $searchData['SearchResult'] as $r )
var_dump( eZContentObjectTreeNode::fetch( $r->MainNodeID )->attribute( 'node_id' ) );
if ( $searchData['SearchCount'] == 0 ) die();
return false;
*/

        if ( $searchData['SearchCount'] == 0 )
        {
            $this->error( 'Nessun oggetto trovato per ' . $row );
            return false;  
        }
        /*
        elseif ( $searchData['SearchCount'] > 1 )
        {            
            foreach ( $searchData['SearchResult'] as $result )
            {
                $remoteID = eZContentObject::fetch( $result->ContentObjectID )->attribute( 'remote_id' );
                //$this->warning( $remoteID );
                if ( strpos( $remoteID, self::REMOTE_PREFIX ) !== false )
                {
                    $originalRemoteID = str_replace( self::REMOTE_PREFIX, '', $remoteID );
                    $originalObject = eZContentObject::fetchByRemoteID( $originalRemoteID );
                    if ( $originalObject )
                    {
                        $this->warning( 'Oggetto clone di ' . $originalObject->attribute( 'name' ) );
                        return false;
                    }
                }
            }
            $this->error( 'Trovati due oggetti di ' . $row );
            return false; 
        }
        */
        
        if ( self::RUNASTEST )
        {
            return $this->processTest( $searchData['SearchResult'], $type, $row );
        }

        foreach ( $searchData['SearchResult'] as $result )
        {
            $originalContentNode = eZContentObjectTreeNode::fetch( $result->MainNodeID );
            $originalContentObject = eZContentObject::fetch( $result->ContentObjectID );
            $this->notice( ' * Elaboro object:#' . $originalContentObject->attribute( 'id' ) . ' node:#' . $originalContentObject->attribute( 'main_node_id' ), false );
            $originalContentObjectRemoteID = $originalContentObject->attribute( 'remote_id' );
            $done = eZContentObject::fetchByRemoteID( self::REMOTE_PREFIX . $originalContentObjectRemoteID );
            //$this->notice( $originalContentObjectRemoteID );
            
            $originalSectionID = $originalContentObject->attribute( 'section_id' );

            if ( is_object( $originalContentObject )
                && $done === NULL
                && strpos( $originalContentObjectRemoteID, self::REMOTE_PREFIX ) === false ) 
            {
                
                $db = eZDB::instance();                
                
                // è in sezione aperta a tutti o retecivica? 
                if ( $originalSectionID == $ReteCivicaSectionID
                    || $originalSectionID == $ApertoATuttiSectionID )
                {
                
                    $copiedContentObject = false;
                    if ( $modificaOggetto )
                    {                       
                        // duplico l'oggetto
                        $this->notice( ' - duplico in', false );
                        $copiedContentObject = $this->copyObject( $originalContentObject, $originalContentNode->attribute( 'parent_node_id' ) );
                        $this->notice( ' object:#' . $copiedContentObject->attribute( 'id' ) . ' node:#' . $copiedContentObject->attribute( 'main_node_id' ), false );
                    }

                    if ( $modificaOggetto && $copiedContentObject )
                    {
                        // modifico l'oggetto duplicato
                        $attributes = $copiedContentObject->fetchAttributesByIdentifier(  array_keys( $attributesToModify )  );                                        

                        foreach( $attributes as $attribute )
                        {                            
                            if ( $modificaOggetto
                                && array_key_exists( $attribute->attribute( 'contentclass_attribute_identifier' ), $attributesToModify ) )
                            {
                                //modifico il testo con il testo di default
                                $this->notice( ' - modifico ' . $attribute->attribute( 'contentclass_attribute_identifier' ), false );
                                $db->begin();
                                $attribute->fromString( $attributesToModify[$attribute->attribute( 'contentclass_attribute_identifier' )] );
                                $attribute->store();
                                $db->commit();
                                
                            }                            
                            
                            $operationResult = eZOperationHandler::execute( 'content',
                                                                            'updatesection',
                                                                            array( 'node_id'             => $copiedContentObject->attribute( 'main_node_id' ),
                                                                                   'selected_section_id' => $ReteCivicaSectionID ),
                                                                            null,
                                                                            true );
                            eZContentCacheManager::clearObjectViewCache( $copiedContentObject->attribute( 'id' ) );
                            
                        }
                        
                        // oscuro l'oggetto originale
                        $this->notice( ' - oscuro oggetto originale', false );
                        $operationResult = eZOperationHandler::execute( 'content',
                                                                        'updatesection',
                                                                        array( 'node_id'             => $originalContentObject->attribute( 'main_node_id' ),
                                                                               'selected_section_id' => $IntranetSectionID ),
                                                                        null,
                                                                        true );
                        eZContentCacheManager::clearObjectViewCache( $originalContentObject->attribute( 'id' ) );
                        
                        self::storeRemoteID( $originalContentObjectRemoteID );
                        
                    }
                    elseif ( $modificaOggetto && !$copiedContentObject )
                    {
                        $this->error( 'Fallita la copia di ' . $originalContentObject->attribute( 'name' ) );
                    }
                        
                    if ( $oscuraAllegati )
                    {                        
                        $attributes = $originalContentObject->fetchAttributesByIdentifier( $attributesToChangeSections );
                        
                        foreach( $attributes as $attribute )
                        { 
                            if ( $attribute->attribute( 'data_type_string' ) == 'ezobjectrelationlist'
                                && in_array( $attribute->attribute( 'contentclass_attribute_identifier' ), $attributesToChangeSections ) )
                            {
                                //modifico sezione degli oggetti file relazionati
                                $relatedObjectIDs = explode( '-', $attribute->toString() );
                                // @phpstan-ignore empty.variable
                                if ( !empty( $relatedObjectIDs ) )
                                {
                                    $this->notice( ' - oscuro ' . $attribute->attribute( 'contentclass_attribute_identifier' ), false );
                                    foreach( $relatedObjectIDs as $relatedObjectID )
                                    {
                                        $relatedObject = eZContentObject::fetch( $relatedObjectID );
                                        if ( $relatedObject )
                                        {
                                            $operationResult = eZOperationHandler::execute( 'content',
                                                                                            'updatesection',
                                                                                            array( 'node_id'             => $relatedObject->attribute( 'main_node_id' ),
                                                                                                   'selected_section_id' => $IntranetSectionID ),
                                                                                            null,
                                                                                            true );
                                        
                                        }
    
                                    }
                                }
                            }
                        }
                        
                        self::storeRemoteID( $originalContentObjectRemoteID );
                        
                    }
                    
                    eZContentCacheManager::clearObjectViewCache( $originalContentObject->attribute( 'id' ) );
                
                }
                else
                {
                    $this->notice( ' già oscurato in sezione ' . $originalSectionID );
                }
            }
            elseif ( $done )
            {
                $this->notice( ' già clonato in object:#' . $done->attribute( 'id' ) . ' node:#' . $done->attribute( 'main_node_id' ) );
                
// fix se la copia fallisce START
                $db = eZDB::instance();  
                if ( $originalSectionID == $ReteCivicaSectionID
                    || $originalSectionID == $ApertoATuttiSectionID )
                {
                    $this->notice( ' - (fix) oscuro oggetto originale', false );
                    $operationResult = eZOperationHandler::execute( 'content',
                                                                    'updatesection',
                                                                    array( 'node_id'             => $originalContentObject->attribute( 'main_node_id' ),
                                                                           'selected_section_id' => $IntranetSectionID ),
                                                                    null,
                                                                    true );
                    eZContentCacheManager::clearObjectViewCache( $originalContentObject->attribute( 'id' ) );
                }
                if ( $oscuraAllegati )
                {
                    $attributes = $originalContentObject->fetchAttributesByIdentifier( $attributesToChangeSections );
                    
                    $originalAttributesContent = array();
                    
                    foreach( $attributes as $attribute )
                    { 
                        if ( $attribute->attribute( 'data_type_string' ) == 'ezobjectrelationlist'
                            && in_array( $attribute->attribute( 'contentclass_attribute_identifier' ), $attributesToChangeSections ) )
                        {
                            if ( $attribute->hasContent() )
                            {
                                $originalAttributesContent[$attribute->attribute( 'contentclass_attribute_identifier' )] = $attribute->toString();
                            }
                            
                            //modifico sezione degli oggetti file relazionati
                            $relatedObjectIDs = explode( '-', $attribute->toString() );
                            // @phpstan-ignore empty.variable
                            if ( !empty( $relatedObjectIDs ) )
                            {
                                $this->notice( ' - (fix) oscuro ' . $attribute->attribute( 'contentclass_attribute_identifier' ), false );
                                foreach( $relatedObjectIDs as $relatedObjectID )
                                {
                                    $relatedObject = eZContentObject::fetch( $relatedObjectID );
                                    if ( $relatedObject )
                                    {
                                        $operationResult = eZOperationHandler::execute( 'content',
                                                                                        'updatesection',
                                                                                        array( 'node_id'             => $relatedObject->attribute( 'main_node_id' ),
                                                                                               'selected_section_id' => $IntranetSectionID ),
                                                                                        null,
                                                                                        true );
                                    
                                    }

                                }
                            }
                        }
                    }
                    
                    // controllo che il cron del webservice non abbia cancellato gli allegati precedenti e perciò eliminato le relazioni se così fosse ri-relaziono i file
                    
                    $attributes = $done->fetchAttributesByIdentifier( $attributesToChangeSections );
                    foreach( $attributes as $attribute )
                    { 
                        if ( $attribute->attribute( 'data_type_string' ) == 'ezobjectrelationlist'
                            && in_array( $attribute->attribute( 'contentclass_attribute_identifier' ), $attributesToChangeSections ) )
                        {
                            if ( !$attribute->hasContent() && isset( $originalAttributesContent[$attribute->attribute( 'contentclass_attribute_identifier' )] ) )
                            {
                                $this->notice( ' - (fix) ri-relaziono i file di ' .  $attribute->attribute( 'contentclass_attribute_identifier' ), false );
                                $db->begin();
                                $attribute->fromString( $originalAttributesContent[$attribute->attribute( 'contentclass_attribute_identifier' )] );
                                $attribute->store();
                                $db->commit();
                            }
                        }
                    }
                    
                }
                if ( $modificaOggetto && $done )
                {
                    // modifico l'oggetto duplicato
                    $attributes = $done->fetchAttributesByIdentifier(  array_keys( $attributesToModify )  );                                        
//print_r($attributes);die();
                    foreach( $attributes as $attribute )
                    {                            
                        if ( $modificaOggetto
                            && array_key_exists( $attribute->attribute( 'contentclass_attribute_identifier' ), $attributesToModify ) )
                        {
                            //modifico il testo con il testo di default
                            $this->notice( ' - (fix) modifico ' . $attribute->attribute( 'contentclass_attribute_identifier' ), false );
                            $db->begin();
                            $attribute->fromString( $attributesToModify[$attribute->attribute( 'contentclass_attribute_identifier' )] );
                            $attribute->store();
                            $db->commit();
                            
                        }                            
                        
                        $operationResult = eZOperationHandler::execute( 'content',
                                                                        'updatesection',
                                                                        array( 'node_id'             => $done->attribute( 'main_node_id' ),
                                                                               'selected_section_id' => $ReteCivicaSectionID ),
                                                                        null,
                                                                        true );
                        eZContentCacheManager::clearObjectViewCache( $done->attribute( 'id' ) );
                        
                    }
                }
                
// fix se la copia fallisce END
                
            }
            elseif ( strpos( $originalContentObjectRemoteID, self::REMOTE_PREFIX ) !== false )
            {
                $done = eZContentObject::fetchByRemoteID(  str_replace( self::REMOTE_PREFIX, '',  $originalContentObjectRemoteID ) );
                if ( $done )
                {
                    $this->notice( ' clone di object:#' . $done->attribute( 'id' ) . ' node:#' . $done->attribute( 'main_node_id' ) );        
                }
            }
            else
            {
                $this->error( 'Object #' . $result->ContentObjectID . ' non esiste' );
            }
        }

        return true;
        
    }
    
    private function getNextRow()
    {                  
        // @phpstan-ignore property.notFound
        if( $this->dataSource->key() !== false )
        {
            // @phpstan-ignore property.notFound
            $row = $this->dataSource->current();
            // @phpstan-ignore property.notFound
            $this->dataSource->next();
        }
        else
        {
            $row = false;
        }       
        return $row;
    }
    
    public function parseCSV( $fileName, $fileType )
    {        
        
        $csvOptions = new CSVOptions( array(
            'csv_path'         => $fileName,
            // @phpstan-ignore property.notFound
            'delimiter'        => $this->csvOptions['delimiter'],
            // @phpstan-ignore property.notFound
            'enclosure'        => $this->csvOptions['enclosure']
        ) );
        // @phpstan-ignore property.notFound
        $this->doc = new CSVDoc( $csvOptions );
        $this->doc->parse();        
        // @phpstan-ignore property.notFound
        $this->dataSource = $this->doc->rows;
        
        $progressBarOptions = array(
            'emptyChar'         => ' ',
            'barChar'           => '='
        );
        // @phpstan-ignore property.notFound
        if ( $this->cli->isQuiet() )
        {
            $progressBarOptions['minVerbosity'] = 10;    
        }
        $progressBar = new ezcConsoleProgressbar( $this->output, count( $this->dataSource ), $progressBarOptions );
        $progressBar->start();
        
        while( $row = $this->getNextRow() )
        {
            $progressBar->advance();
            $this->process( $row, $fileType );

//eZExecution::cleanExit();

        }
        $progressBar->finish();
        $this->notice('');
        
    }
    
    public function run()
    {
        $files = $this->getCSVFiles();
        foreach( $files as $fileName => $values )
        {
            if ( file_exists( $fileName ) )
            {
                $this->currentFile = basename( $values['fileName'] );
                $this->currentFileError[$this->currentFile] = array();
                $this->notice( 'Elaboro il file di ' . $values['object']->attribute( 'name' ) );                
                $this->parseCSV( $fileName, $values['type'] );
                
                $db = eZDB::instance();
                $attributes = $values['object']->fetchAttributesByIdentifier( array( 'enqueue', 'note' ) );

                foreach( $attributes as $attribute )
                {
                    if ( $attribute->attribute( 'contentclass_attribute_identifier' ) == 'note' )
                    {                        
                        $attribute->fromString( utf8_encode( implode( "\n\n", $this->currentFileError[$this->currentFile] ) ) );
                        $db->begin();
                        $attribute->store();
                        $db->commit();   
                    }
                    elseif ( $attribute->attribute( 'contentclass_attribute_identifier' ) == 'enqueue' )
                    {
                        if ( count( $this->currentFileError[$this->currentFile] ) == 0 )
                        {
                            $attribute->fromString( 0 );
                            $db->begin();
                            $attribute->store();
                            $db->commit();
                        }
                    }
                                        
                    eZContentCacheManager::clearObjectViewCache( $values['object']->attribute( 'id' ) );
                }

                
            }
            else
            {
                $this->error( 'File di ' . $values['object']->attribute( 'name' ) . ' non trovato' );
            }
        }
    }
    
    private function copyObject( $object, $newParentNodeID, $newRemoteID = false, $allVersions = false, $sectionID = 0 )
    {
        if ( !$newParentNodeID )
        {
            $this->notice( ' (missing parent node) ', false );
            return false;
        }
        
        if ( !$newRemoteID )
            $newRemoteID = self::REMOTE_PREFIX . $object->attribute( 'remote_id' );
    
        // check if we can create node under the specified parent node
        if( ( $newParentNode = eZContentObjectTreeNode::fetch( $newParentNodeID ) ) === null )
            return false;
    
        $classID = $object->attribute('contentclass_id');
    
        if ( !$newParentNode->checkAccess( 'create', $classID ) )
        {            
            $this->notice( ' (checkAccess error) ', false );
            return false;
        }
    
        $db = eZDB::instance();
        
        $newObject = $object->copy( $allVersions );
        // We should reset section that will be updated in updateSectionID().
        // If sectionID is 0 then the object has been newly created
        $db->begin();
        $newObject->setAttribute( 'section_id', $sectionID );
        $newObject->setAttribute( 'remote_id', $newRemoteID );
        $newObject->store();
        $db->commit();
    
        $curVersion        = $newObject->attribute( 'current_version' );
        $curVersionObject  = $newObject->attribute( 'current' );
        $newObjAssignments = $curVersionObject->attribute( 'node_assignments' );
        unset( $curVersionObject );
    
        // remove old node assignments
        foreach( $newObjAssignments as $assignment )
        {
            $assignment->purge();
        }
    
        // and create a new one
        $nodeAssignment = eZNodeAssignment::create( array(
                                                         'contentobject_id' => $newObject->attribute( 'id' ),
                                                         'contentobject_version' => $curVersion,
                                                         'parent_node' => $newParentNodeID,
                                                         'is_main' => 1
                                                         ) );
        $db->begin();
        $nodeAssignment->store();
        $db->commit();
    
        // publish the newly created object
        eZOperationHandler::execute( 'content', 'publish', array( 'object_id' => $newObject->attribute( 'id' ),
                                                                  'version'   => $curVersion ) );
        // Update "is_invisible" attribute for the newly created node.
        $newNode = $newObject->attribute( 'main_node' );
        eZContentObjectTreeNode::updateNodeVisibility( $newNode, $newParentNode );
    
        $new = $newNode->attribute( 'object' );
        $eZSolr = eZSearch::getEngine();
        $eZSolr->addObject( $new, true );
        return $new;
    }

    private function log( $message, $method = false, $level = 'notice', $breakLine = true )
    {
        $cliMethod = $method ? ' (' . $method . ')' : '';
        
        if ( $this->currentFile )
        {
            $currentFile = urlencode( $this->currentFile );
            eZLog::write( '[' . strtoupper( $level ) . '] ' . $message . $cliMethod, 'oscura_atti/' . $currentFile . '.log' );
        }
        
        eZLog::write( '[' . strtoupper( $level ) . '] ' . $message . $cliMethod, 'oscura_atti.log' );
        switch( $level )
        {
            case 'warning':
            {
                // @phpstan-ignore property.notFound
                if ( !$this->cli->isQuiet() )    
                    // @phpstan-ignore property.notFound
                    $this->cli->warning( $message . $cliMethod, $breakLine );
            } break;
            
            case 'error':
            {
                // @phpstan-ignore property.notFound
                if ( !$this->cli->isQuiet() )
                    // @phpstan-ignore property.notFound
                    $this->cli->error( $message . $cliMethod, $breakLine );
            } break;
            
            case 'notice':
            {
                // @phpstan-ignore property.notFound
                if ( !$this->cli->isQuiet() )
                    // @phpstan-ignore property.notFound
                    $this->cli->notice( $message . $cliMethod, $breakLine );
            } break;
            
            default:
            {
                // @phpstan-ignore property.notFound
                if ( !$this->cli->isQuiet() )
                    // @phpstan-ignore property.notFound
                    $this->cli->output( $message . $cliMethod, $breakLine );                
            } break;
        }
    }
    
    private function output( $message, $breakLine = true, $method = false )
    {
        $this->log( $message, $method, 'output', $breakLine );
    }
    
    private function notice( $message, $breakLine = true,  $method = false )
    {
        $this->log( $message, $method, 'notice', $breakLine );
    }
    
    private function warning( $message, $breakLine = true,  $method = false )
    {
        $this->log( $message, $method, 'warning', $breakLine );
    }
    
    private function error( $message, $breakLine = true,  $method = false )
    {
        $this->currentFileError[$this->currentFile][] = $message;
        $this->log( $message, $method, 'error', $breakLine );
    }
    
    private function is_utf8($str) //http://www.php.net/manual/en/function.mb-detect-encoding.php#85294
    {
        $c=0; $b=0;
        $bits=0;
        $len=strlen($str);
        for($i=0; $i<$len; $i++){
            $c=ord($str[$i]);
            if($c > 128){
                if(($c >= 254)) return false;
                elseif($c >= 252) $bits=6;
                elseif($c >= 248) $bits=5;
                elseif($c >= 240) $bits=4;
                elseif($c >= 224) $bits=3;
                elseif($c >= 192) $bits=2;
                else return false;
                if(($i+$bits) > $len) return false;
                while($bits > 1){
                    $i++;
                    $b=ord($str[$i]);
                    if($b < 128 || $b > 191) return false;
                    $bits--;
                }
            }
        }
        return true;
    }
    
    public static function deleteRemoteID( $remoteID )
    {
        $sitedata = eZSiteData::fetchByName( 'oscura_atti' );
        if ( $sitedata !== NULL )
        {
            $data = unserialize( $sitedata->attribute( 'value' ) );
            $pleaseStore = false;
            foreach( $data as $index => $item )
            {
                if ( $item == $remoteID )
                {
                    unset( $data[$index] );
                    $pleaseStore = true;
                    break;
                }
            }
            
            if ( $pleaseStore )
            {                
                $sitedata->setAttribute( 'value', serialize( $data ) );
                $sitedata->store();
                return true;
            }  
        }
        return false;
    }
    
    public static function existsRemoteID( $remoteID )
    {        
        $sitedata = eZSiteData::fetchByName( 'oscura_atti' );
        if ( $sitedata !== NULL )
        {
            $data = unserialize( $sitedata->attribute( 'value' ) );
            if( in_array( $remoteID, $data ) )
            {
                if ( eZContentObject::fetchByRemoteID( $remoteID ) )
                {                    
                    return true;
                }
                else
                {
                    self::deleteRemoteID( $remoteID );
                }
            }
        }
        return false;
    }
    
    public static function storeRemoteID( $remoteID )
    {        
        $sitedata = eZSiteData::fetchByName( 'oscura_atti' );
        if ( $sitedata === NULL )
        {            
            $sitedata = new eZSiteData( array(
                'name' => 'oscura_atti',
                'value' => serialize( array( $remoteID ) )
            ));
            $sitedata->store();
        }
        else
        {
            $data = unserialize( $sitedata->attribute( 'value' ) );
            if ( !in_array( $remoteID, $data ) )
            {
                $data[] = $remoteID;
                $sitedata->setAttribute( 'value', serialize( $data ) );
                $sitedata->store();
            }            
        }
    }
    
    /**
     * Oscura l'oggetto
     * *
     * @var eZContentObject $object
     *
     * @return $operationResult array( status => bool )
     */
    
    public static function oscuraOggetto( $contentObject )
    {
        $IntranetSectionID = eZINI::instance( 'oscuraatti.ini' )->variable( 'OscuraAttiSettings', 'RestrictedSection' );
        $operationResult = eZOperationHandler::execute( 'content',
                                                        'updatesection',
                                                        array( 'node_id'             => $contentObject->attribute( 'main_node_id' ),
                                                               'selected_section_id' => $IntranetSectionID ),
                                                        null,
                                                        true );
        return $operationResult;
    }
    
    /**
     * Oscura gli allegati dell'oggetto
     * *
     * @var eZContentObject $object
     *
     * @return $operationResult array( status => bool )
     */
    
    public static function oscuraAllegati( $contentObject )
    {
        $attributesToChangeSections = eZINI::instance( 'oscuraatti.ini' )->variable( 'OscuraAttiSettings', 'ChangeSectionToAttributes' );
        $IntranetSectionID = eZINI::instance( 'oscuraatti.ini' )->variable( 'OscuraAttiSettings', 'RestrictedSection' );
        $attributes = $contentObject->fetchAttributesByIdentifier( $attributesToChangeSections );
        $result = array();           
        foreach( $attributes as $attribute )
        { 
            if ( $attribute->attribute( 'data_type_string' ) == 'ezobjectrelationlist'
                && in_array( $attribute->attribute( 'contentclass_attribute_identifier' ), $attributesToChangeSections ) )
            {                
                $relatedObjectIDs = explode( '-', $attribute->toString() );
                // @phpstan-ignore empty.variable
                if ( !empty( $relatedObjectIDs ) )
                {                        
                    foreach( $relatedObjectIDs as $relatedObjectID )
                    {
                        $relatedObject = eZContentObject::fetch( $relatedObjectID );
                        if ( $relatedObject )
                        {
                            $operationResult = eZOperationHandler::execute( 'content',
                                                                            'updatesection',
                                                                            array( 'node_id'             => $relatedObject->attribute( 'main_node_id' ),
                                                                                   'selected_section_id' => $IntranetSectionID ),
                                                                            null,
                                                                            true );
                            $result[$attribute->attribute( 'contentclass_attribute_identifier' )][$relatedObjectID] = $operationResult;
                        }
                    }
                }
            }
        }
        return $result;
    }
    
    /**
     * Restituisce il clone dell'oggetto o false
     *
     * @var eZContentObject $object
     *
     * @return eZContentObject or false
     */
    
    public static function hasPrivacyClonedObject( $object )
    {
        if ( is_numeric( $object ) )
        {
            $object = eZContentObject::fetch( $object );
        }
        
        if ( $object instanceof eZContentObject )
        {
            $privacyClonedObject = eZContentObject::fetchByRemoteID( self::REMOTE_PREFIX . $object->attribute( 'remote_id' ) );
            if ( $privacyClonedObject instanceof eZContentObject )
            {
                return $privacyClonedObject;
            }            
        }
        
        return false;
    }
    
    /**
     * Controlla se l'oggetto è un clone
     *
     * @var eZContentObject $object
     *
     * @return (bool)
     *
     */
    
    public static function isPrivacyClonedObject( $object )
    {
        if ( is_numeric( $object ) )
        {
            $object = eZContentObject::fetch( $object );
        }
        
        if ( $object instanceof eZContentObject )
        {
            return (bool) ( strpos( $object->attribute( 'remote_id' ), self::REMOTE_PREFIX ) !== false );
        }
        
        return false;
    }
    
    /**
     * Restituisce l'oggetto originale, qualora l'oggetto passato sia un clone o null
     * 
     * @var eZContentObject $object
     *
     * @return eZContentObject or null
     *
     */
    
    public function isPrivacyClonedObjectFrom( $object )
    {
        if ( !self::isPrivacyClonedObject( $object ) )
        {
            return false;
        }
        
        if ( is_numeric( $object ) )
        {
            $object = eZContentObject::fetch( $object );
        }
        return eZContentObject::fetchByRemoteID( str_replace( self::REMOTE_PREFIX, '', $object->attribute( 'remote_id' ) ) );
    }
  
}

?>