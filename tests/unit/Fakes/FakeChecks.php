<?php
/**
 * Doctor checks for unit tests.
 */

use StorePulse\ConversionTracking\Doctor\AbstractCheck;
use StorePulse\ConversionTracking\Doctor\Check;
use StorePulse\ConversionTracking\Doctor\Context;
use StorePulse\ConversionTracking\Doctor\Finding;

/**
 * Sample detector: finds what $GLOBALS['wcct_test_doctor'][ id ] says, nothing when unset.
 */
class WCCT_Test_Sample_Check extends AbstractCheck {

    public $id;
    public $scope;
    public $applies;
    public $runs = 0;

    public function __construct( $id = 'sample', $scope = Check::LOCAL, $applies = true ) {
        $this->id      = $id;
        $this->scope   = $scope;
        $this->applies = $applies;
    }

    public function id() {
        return $this->id;
    }

    public function label() {
        return 'Sample ' . $this->id . ' is fine';
    }

    public function scope() {
        return $this->scope;
    }

    public function applies() {
        return $this->applies;
    }

    public function run( Context $context ) {
        ++$this->runs;
        $GLOBALS['wcct_test_doctor_order'][] = $this->id;

        $findings = array();

        foreach ( isset( $GLOBALS['wcct_test_doctor'][ $this->id ] ) ? $GLOBALS['wcct_test_doctor'][ $this->id ] : array() as $args ) {
            $findings[] = $this->finding( $args );
        }

        return $findings;
    }
}

/**
 * A check that breaks.
 */
class WCCT_Test_Broken_Check extends WCCT_Test_Sample_Check {

    public function run( Context $context ) {
        throw new RuntimeException( 'broken' );
    }
}

/**
 * Registered by class name; always finds one unsure problem.
 */
class WCCT_Test_Named_Check extends AbstractCheck {

    public function id() {
        return 'named';
    }

    public function label() {
        return 'Named is fine';
    }

    public function run( Context $context ) {
        return array(
            $this->finding(
                array(
                    'found'      => 'Named found something',
                    'why'        => 'It matters.',
                    'confidence' => Finding::UNSURE,
                )
            ),
        );
    }
}

/**
 * A check whose id() throws.
 */
class WCCT_Test_No_Id_Check extends WCCT_Test_Sample_Check {

    public function id() {
        throw new RuntimeException( 'no id' );
    }
}

/**
 * Not a check.
 */
class WCCT_Test_Not_A_Check {
}

/**
 * Abstract, so it can't be made.
 */
abstract class WCCT_Test_Abstract_Check extends AbstractCheck {
}
