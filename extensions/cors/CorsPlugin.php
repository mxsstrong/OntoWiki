<?php
/**
 * This file is part of the {@link http://ontowiki.net OntoWiki} project.
 *
 * @category   OntoWiki
 * @package    OntoWiki_extensions_plugins
 * @copyright Copyright (c) 2010, {@link http://aksw.org AKSW}
 * @license http://opensource.org/licenses/gpl-license.php GNU General Public License (GPL)
 */

/**
 * The main class for the cors plugin.
 *
 * @category   OntoWiki
 * @package    OntoWiki_extensions_plugins
 * @copyright  Copyright (c) 2010 {@link http://aksw.org aksw}
 * @license    http://opensource.org/licenses/gpl-license.php GNU General Public License (GPL)
 * @author     Sebastian Tramp <tramp@informatik.uni-leipzig.de>
 */
class CorsPlugin extends OntoWiki_Plugin
{
    /*
     * our event method
     */
    public function onRouteStartup()
    {
        $this->addCorsHeader();
    }

    /*
     * here we add the header field(s)
     */
    private function addCorsHeader() {
        $response = Zend_Controller_Front::getInstance()->getResponse();

        /*
         * TODO: allow more CORS header fields here
         */
        if (isset ($this->_privateConfig->accessControlAllowOrigin) ) {
            $value = '"'.$this->_privateConfig->accessControlAllowOrigin.'"';
            $response->setHeader('Access-Control-Allow-Origin', $value, true);
        }
    }
}
