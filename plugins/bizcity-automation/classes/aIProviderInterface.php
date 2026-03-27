<?php

interface WaicAIProviderInterface {
	public function init();
	public function setApiOptions( $options );
	public function getEngine();
	public function getText( $params, $stream = null );
	public function getImage( $params );
}
