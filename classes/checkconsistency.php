<?php

class OcCheckConsistency
{
    private static $instance;

    protected $db;

    protected $verbose = false;

    /**
     * @var ezcConsoleOutput
     */
    protected $output;

    protected $outputOptions = array();

    public static function instance()
    {
        if (self::$instance === null) {
            self::$instance = new OcCheckConsistency();
        }

        return self::$instance;
    }

    protected function __construct()
    {
        $this->db = eZDB::instance();
    }

    protected function log($string)
    {
        eZLog::write($string, 'checkconsistency.log');
    }

    public function verbose()
    {
        $this->verbose = true;
        $this->output = new ezcConsoleOutput();
        $this->output->formats->success->color = 'green';
        $this->output->formats->failure->color = 'red';

        $this->outputOptions = array(
            'successChar' => $this->output->formatText( '+', 'success' ),
            'failureChar' => $this->output->formatText( '-', 'failure' ),
        );


    }

    public function checkObjectsWithoutAttributes($doFix = false)
    {
        $objects = $this->db->arrayQuery(
            "SELECT id, contentclass_id, current_version FROM ezcontentobject"
        );

        if ($this->output) {
            $status = new ezcConsoleStatusbar($this->output, $this->outputOptions);
        }

        $i = 0;
        foreach ($objects as $item) {
            $hasAttribute = $this->db->arrayQuery(
                "SELECT contentobject_id FROM ezcontentobject_attribute WHERE contentobject_id = " . $item['id']
            );

            if (empty( $hasAttribute )) {

                if ($doFix) {
                    $this->log('Delete ezcontentobject.id ' . $item['id']);
                    $this->db->begin();
                    $this->db->query("DELETE FROM ezcontentobject WHERE ezcontentobject.id = " . $item['id']);
                    $this->db->query(
                        "DELETE FROM ezcontentobject_name WHERE ezcontentobject_name.contentobject_id = " . $item['id']
                    );
                    if ($item['contentclass_id'] == 4) {
                        $this->db->query("DELETE FROM ezuser WHERE ezuser.contentobject_id = " . $item['id']);
                    }
                    $this->db->commit();
                } else {
                    // @phpstan-ignore isset.variable
                    if (isset($status)) $status->add(false);
                }
                $i++;
            }
            // @phpstan-ignore isset.variable
            if (isset($status)) $status->add(true);
        }

        if ($this->output){
            $this->output->outputLine();
            $this->output->outputLine( 'Successes: ' . $status->getSuccessCount() . ', Failures: ' . $status->getFailureCount() );
        }

        return $i;
    }

    public function checkNodesConsistency($doFix = false)
    {
        $objects = $this->db->arrayQuery("SELECT id, name, status FROM ezcontentobject WHERE status = " . eZContentObject::STATUS_PUBLISHED);

        $count = 0;

        if ($this->output) {
            $status = new ezcConsoleStatusbar($this->output, $this->outputOptions);
        }

        foreach ($objects as $item) {

            $hasNode = $this->db->arrayQuery(
                "SELECT contentobject_id FROM ezcontentobject_tree WHERE contentobject_id = " . $item['id']
            );

            $hasTrashNode = $this->db->arrayQuery(
                "SELECT contentobject_id FROM ezcontentobject_trash WHERE contentobject_id = " . $item['id']
            );

            if (empty( $hasNode ) && empty( $hasTrashNode )) {
                // @phpstan-ignore isset.variable
                if (isset($status)) $status->add(false);
                $count++;
            } else {
                // @phpstan-ignore isset.variable
                if (isset($status)) $status->add(true);
            }
        }

        if ($doFix) {

            foreach ($objects as $item) {
                $hasNode = $this->db->arrayQuery(
                    "SELECT contentobject_id FROM ezcontentobject_tree WHERE contentobject_id = " . $item['id']
                );
                $hasTrashNode = $this->db->arrayQuery(
                    "SELECT contentobject_id FROM ezcontentobject_trash WHERE contentobject_id = " . $item['id']
                );

                if (empty( $hasNode ) && empty( $hasTrashNode )) {

                    $this->log('Delete ezcontentobject.id ' . $item['id']);
                    $this->db->begin();
                    $this->db->query(
                        "DELETE FROM ezcontentobject WHERE id = " . $item['id']
                    );

                    $this->db->query(
                        "DELETE FROM ezcontentobject_name WHERE contentobject_id = " . $item['id']
                    );

                    $this->db->query(
                        "DELETE FROM ezcontentobject_link WHERE (from_contentobject_id = " . $item['id'] . " OR to_contentobject_id = " . $item['id'] . ")"
                    );

                    $this->db->query(
                        "DELETE FROM ezcontentobject_version WHERE contentobject_id = " . $item['id']
                    );

                    $this->db->query(
                        "DELETE FROM ezcontentobject_attribute WHERE contentobject_id = " . $item['id']
                    );

                    $this->db->query(
                        "DELETE FROM eznode_assignment WHERE contentobject_id = " . $item['id']
                    );
                    $this->db->commit();
                }
            }
        }
        if ($this->output){
            $this->output->outputLine();
            $this->output->outputLine( 'Successes: ' . $status->getSuccessCount() . ', Failures: ' . $status->getFailureCount() );
        }

        return $count;
    }

    public function checkObjectsWithoutCurrentVersion($doFix = false)
    {
        $resources = $this->db->arrayQuery("SELECT id, current_version, name FROM ezcontentobject");

        if ($this->output) {
            $status = new ezcConsoleStatusbar($this->output, $this->outputOptions);
        }

        $i = 0;
        foreach ($resources as $item) {

            //check if current_version has content attributes
            $hasAttribute = $this->db->arrayQuery(
                "SELECT contentobject_id FROM ezcontentobject_attribute WHERE contentobject_id = " . $item['id'] . " AND version = " . $item['current_version']
            );

            if (empty( $hasAttribute )) {
                $previousCurrentVersion = $item['current_version'] - 1;
                if ($previousCurrentVersion > 0) {
                    if ($doFix) {
                        $this->log('Update current_version in ezcontentobject.id ' . $item['id']);
                        $this->db->begin();
                        $this->db->query(
                            "UPDATE ezcontentobject SET current_version = " . $previousCurrentVersion . " WHERE id = " . $item['id']
                        );
                        $this->db->commit();
                    }
                    // @phpstan-ignore isset.variable
                    if (isset($status)) $status->add(false);
                } else {
                    // @phpstan-ignore isset.variable
                    if (isset($status)) $status->add(true);
                }
                $i++;
            }
        }
        if ($this->output){
            $this->output->outputLine();
            $this->output->outputLine( 'Successes: ' . $status->getSuccessCount() . ', Failures: ' . $status->getFailureCount() );
        }

        return $i;
    }

    public function checkUserWithoutLogin($doFix = false)
    {
        if ($this->output) {
            $status = new ezcConsoleStatusbar($this->output, $this->outputOptions);
        }
        $resources = $this->db->arrayQuery("SELECT * FROM ezuser WHERE login = ''");
        if ($doFix) {

            return 0; //@todo

//            foreach ($resources as $item) {
//
//                $this->log('Delete ezuser.contentobject_id ' . $item['id']);
//                $this->db->begin();
//
//                $this->db->query(
//                    "UPDATE ezcontentobject_attribute SET data_text='' WHERE contentobject_id = " . intval($item['contentobject_id']) . " AND data_type_string = 'ezuser'"
//                );
//                $this->db->query(
//                    "DELETE FROM ezuser WHERE contentobject_id = " . intval($item['contentobject_id'])
//                );
//                $this->db->commit();
//            }
        }

        return count($resources);
    }

    public function checkPendingActions($doFix = false)
    {
        if ($this->output) {
            $status = new ezcConsoleStatusbar($this->output, $this->outputOptions);
        }

        $count = 0;

        $db = eZDB::instance();

        $offset = 0;
        $limit = 50;

        while( true )
        {
            $entries = $db->arrayQuery(
                "SELECT param FROM ezpending_actions WHERE action = 'index_object' GROUP BY param ORDER BY min(created)",
                array( 'limit' => $limit, 'offset' => $offset )
            );

            if ( is_array( $entries ) && count( $entries ) != 0 )
            {
                foreach ( $entries as $entry )
                {
                    $objectID = (int)$entry['param'];

                    $db->begin();
                    $object = eZContentObject::fetch( $objectID );
                    $removeFromPendingActions = !($object instanceof eZContentObject);
                    if ( $removeFromPendingActions )
                    {
                        // @phpstan-ignore isset.variable
                        if (isset($status)) $status->add(false);
                        if ($doFix) {
                            $db->query("DELETE FROM ezpending_actions WHERE action = 'index_object' AND param = '$objectID'");
                        }else{
                            ++$offset;
                        }
                    }
                    else
                    {
                        // @phpstan-ignore isset.variable
                        if (isset($status)) $status->add(true);
                        ++$offset;
                    }

                    $db->commit();
                    $count++;
                }
            }
            else
            {
                break;
            }
        }

        // @phpstan-ignore isset.variable
        if ($this->output && isset($status)){
            $this->output->outputLine();
            $this->output->outputLine( 'Successes: ' . $status->getSuccessCount() . ', Failures: ' . $status->getFailureCount() );
        }

        return $count;
    }

}
