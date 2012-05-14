<?php
	require_once( '../simplebus.php' );
	require_once( '../../Ariadne-Component-Library/ar.php' );
	/**
		this is an example mbus system - it implements a feed reader application 
		that can read rss and atom feeds and outputs a nice html representation
		based on the mbus architecture.
	*/
	

	class busRSSWidget extends simpleBus {
	
		public function init( $self ) {
			$httpClient    = new busHTTPClient( $self );
			$rssParser     = new busRSSParser( $self );
			$htmlGenerator = new busRSSHTMLGenerator( $self );
			
			$self
			->listen( 'in', '/content/items/' )
			->onmatch( 
				function( $message ) use ( $self ) {
					$self->say( 
						'in', 
						array( 
							'htmlFeed' => array( 
								'items' => $message['content']['items'] 
							)
						)
					);
				}
			);
			
			$self
			->listen( 'in', '/output/' )
			->onmatch(
				function( $message ) use ( $self )  {
					$self->say( 
						'out', 
						array( 'output' => array( 'text/html' => $message['output']['text/html'] ) ) 
					);
				}
			);
			
			$self
			->listen( 'out', '/configuration/feed/' )
			->onmatch(
				function( $message ) use ( $self, $feeds )  {	
					$self->say( 
						'in', 
						array( 'fetch' => array( 'url' => $message['configuration']['feed'] ) ) 
					);
				}
			);
			
		}
		
	}
	
	class busHTTPClient extends simpleBus {
	
		public function init( $self ) {
			$self
			->listen( 'out', '/fetch/url/' )
			->onmatch(
				function( $message ) use ( $self, $httpClient ) {
					$httpClient = ar\connect\http::client();
					$feeds = $message['fetch']['url'];
					if ( !is_array( $feeds ) ) {
						$feeds = array( $feeds );
					}
					foreach ( $feeds as $feed ) {
						$content = $httpClient->get( $feed );
						$headers = $httpClient->responseHeaders;
						foreach( $headers as $header ) {
							if ( preg_match( '/Content-Type:([^;]*)(;.*)?$/', $header, $matches ) ) {
								$contentType = $matches[1];
								break;
							}
						}
						$self->say( 
							'out', 
							array( 
								'url' => $feed,
								'type' => $contentType,
								'content' => $content,
								'headers' => $headers
							)
						);
					}
				}
			);
		}
		
	}
	
	class busRSSParser extends simpleBus {
		
		public function init( $self ) {
			$self
			->listen( 'out', '/type:"text/xml"/' )
			->onmatch(
				function( $message ) use ( $self )  {
					try {
						$rss = ar\connect\rss::parse( $message['content'] );
						$message['content'] = array(
							'items' => $rss->items,
							'channel' => $rss->channel,
						);
						$message['type'] = 'array';
						$self->say( 'out', $message ); 
					} catch( ar\Exception $e ) {
					}
				}
			);
		}
	
	}
	
	class busRSSHTMLGenerator extends simpleBus {
		protected $limit = null;
		protected $offset = null;
		protected $items = null;
		
		public function init( $self ) {
		
			$self
			->listen( 'out', '/htmlFeed/items/' )
			->onmatch(
				function( $message ) use ( $self )  {
					$self->say( 
						'out', 
						array(
							'output' => array(
								'text/html' => $self->getHTML( $message['htmlFeed']['items'] ) 
							)
						)
					);
				} 
			);
		}
		
		public function getHTML( $items ) {
			$result = '<ul>';
			foreach ( $items as $item ) {
				$result .= '<li>' . $item['title'] . '</li>';
			}
			$result .= '</ul>';
			return $result;
		}
	}


	/* glue logic to build the application */
	$feed      = 'http://planet-php.org/rss/';
	$limit     = 10;
	$offset    = $_GET['offset'];
	$feedFound = false;
	
	$applicationBus = new simpleBus();
	$rssWidget      = new busRSSWidget( $applicationBus );
	
	$applicationBus
	->listen( 'in', '/output/text\/html/' )
	->onmatch(
		function( $message ) use ( $feedFound ) {
			echo <<< EOF
<!doctype html>
<html>
<head>
	<title>Example mbus application: RSS Reader</title>
</head>
<body>
	<h1>Example mbus application: RSS Reader</h1>
EOF;
			echo $message['output']['text/html'];
			$feedFound = true;
			echo <<< EOF
</body>
</html>
EOF;
		} 
	)
	->ontimeout( '5 seconds', function() use ( $applicationBus ) {
		echo <<< EOF
<!doctype html>
<html>
<head>
	<title>Error</title>
</head>
<body>
	<h1>Error</h1>
	<p>Cannot read the RSS Feed</p>
</body>
</html>
EOF;
		unset( $applicationBus );
	})
	->onclose( function( $timeoutHandler ) use ( $feedFound ) {
		if ( $timeoutHandler && !$feedFound ) {
			$timeoutHandler();
		}
	});
	
	$applicationBus->say( 'in', array( 
		'configuration' => array(
			'feed'  => $feed,
			'limit' => $limit
		), 
		'input' => array( 
			'offset' => $offset
		)
	) );
	
	unset( $applicationBus );
	
?>