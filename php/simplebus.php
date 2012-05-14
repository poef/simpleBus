<?php
	
/*
	class example extends simpleBus {
	
		function init( $self ) {
			$self
			->listen('out', '/configuration/rss/', '/config/rss/,/shortsyntax/' )
			->onmatch( function( $message ) use ( $self ) {
				$self
				->listen( 'in', '/response/' )
				->onmatch( function( $message ) {
					echo 'response heard';
				})
				->ontimeout( 5, function() {
					echo 'timeout';
				})
				->say( 'in', $message );
			})
			->ontimeout( '5 seconds', function( $message ) {
				echo "I didn't hear you";
			})
			->onclose( function( $timeoutHandler ) {
				$timeoutHandler();
			});
		}
		
	}
					
*/

class simpleBus {

	protected $bus;
	protected $listeners = array();
	
	public function __construct( $bus = null ) {
		srand();
		$this->bus = $bus;
		$this->init( $this );
	}
	
	public function init( $self ) {
	}
	
	public function listen( $bus ) {
		$args = func_get_args();
		$args[0] = $this;
		if ( $bus == 'out' ) {
			return call_user_func_array( 
				array( $this->bus, 'addListener' ), $args );
		} else {
			return call_user_func_array( 
				array( $this, 'addListener' ), $args );
		}
	}
	
	public function say( $bus, $message, $sender = null ) {
		if ( !$sender ) {
			$sender = $this;
		}
		if ( $bus == 'out' ) {
			return $this->bus->say( 'in', $message, $sender );
		} else {
			$message = json_decode( json_encode( $message ), true ); 
			foreach( $this->listeners as $id => $listener ) {
				if ( $listener->object != $sender ) {
					if ( $listener->matches( $message ) ) {
						$listener( $message );
					}
				}
			}
		}
		return $this;
	}
	
	public function addListener( $object, $filter ) {
		$filters = func_get_args();	
		array_shift( $filters );
		do {
			$id = rand(1,2147483647);
		} while ( isset($this->listeners[$id]) );
		$listener = new simpleBusListener( $id, $this, $object, $filters );
		$this->listeners[$id] = $listener;
		return $listener;
	}
	
	public function removeListener( $listenerId ) {
		unset( $this->listeners[$listenerId] );
	}
	
	public function close() {
		foreach ( $this->listeners as $listener ) {
			$listener->remove();
		}	
	}
	
	public function __destruct() {
		$this->close();
	}
}

class simpleBusListener {
	protected $id, $bus, $filters, $method, $onlyOnce, 
		$timeout, $timeoutHandler, $closeHandler;
	public $object = null;
	
	public function __construct( $id, $bus, $object, $filters ) {
		$this->id = $id;
		$this->bus = $bus;
		$this->filters = $filters;
		$this->object = $object;
	}
	
	public function __invoke( $message ) {
		if ( $this->onlyOnce ) {
			$this->remove();
		}
		return call_user_func( $this->method, $message );
	}

	public function __destruct() {
		$this->remove();
	}
	
	public function once() {
		$this->onlyOnce = true;
		return $this;
	}
	
	public function onmatch( $method ) {
		$this->method = $method;
		return $this;
	}
	
	public function remove() {
		if ( $this->closeHandler ) {
			call_user_func($this->closeHandler, $this->timeoutHandler );
			unset( $this->closeHandler );
		}
		if ( $this->bus ) {
			$this->bus->removeListener( $this->id );
			unset( $this->bus );
		}
	}
	
	public function ontimeout( $timeout, $timeoutHandler ) {
		if ( is_string($timeout)) {
			$this->timeout = strtotime($timeout);
		} else {
			$this->timeout = microtime(true) + (float)($timeout/1000);
		}
		$this->timeoutHandler = $timeoutHandler;
		return $this;
	}
	
	public function handleTimeout() {
		unset( $this->timeout );
		if ( $this->timeoutHandler ) {
			call_user_func( $this->timeoutHandler );
			unset( $this->timeoutHandler );
		}
	}
	
	public function onclose( $closeHandler ) {
		$this->closeHandler = $closeHandler;
		return $this;
	}
	
	public function matches( $message ) {
		if ( $this->timeout && $this->timeout < microtime(true) ) {
			$this->handleTimeout();
		} else {
			foreach ( $this->filters as $filter ) {
				if ( simplePath::matchFilter( $message, $filter ) ) {
					unset( $this->timeout );
					unset( $this->timeoutHandler );
					return true;
				}
			}
			return false;
		}
	}
}

/*
	'/foo/'      
	-> matches a message with root entry 'foo'
	'/foo/,/bar/'
	-> matches a message with both /foo/ and /bar/
	'/foo:bar/'  
	-> matches a message with root entry 'foo' with string value 'bar'
	'/foo/bar/'  
	-> matches a message with root entry 'foo' with a child entry 'bar'
	'/foo/.../bar/'
	-> matches a message with root entry 'foo' with a descendant entry
	   'bar'
	'/foo:"bar"/'
	-> matches a message with root entry 'foo' whose value contains the
	   string 'bar'
	'!/foo/'
	-> matches a message that has no root entry 'foo'
	'!/.../foo/'
	-> matches a message that has no entry 'foo' at any level
	'/foo:"[a-z][a-z0-9]*"/'
	-> matches a message that has a root entry foo with a value that 
	   matches the regular expression / [a-z][a-z0-9]* /i
*/
class simplePath {

	static public function matchFilter( $message, $filter ) {
		if ( !$filter ) {
			return true;
		}
		$quotedRe = '"(?:[^"\\\\]|\\\\.)*"';
		$searchPathRe = "([^\",]|$quotedRe)+";
		$firstSearchPathRe = "/^($searchPathRe)(,|$)/";	
		$searchPath = false;
		while ( preg_match( $firstSearchPathRe, $filter, $matches ) ) {
			$searchPath = $matches[1];
			$filter = substr( $filter, strlen( $searchPath ) + 1 );
			if ( !self::matchSearchPath( 
				$message, 
				trim( $searchPath ) ) ) 
			{
				return false;
			}
		}
		if ( !$searchPath ) {
			throw new Exception("syntax error in filter ".$filter);
		}
		return true;
	}
	
	static public function matchSearchPath( $message, $searchPath ) {
		if ( !$searchPath ) {
			return true;
		}
		if ( $searchPath[0] == '!' ) {
			$negate = true;
			$searchPath = substr( $searchPath, 1 );
		} else {
			$negate = false;
		}
		$nameRe = '([^/:\\\\]|[\\\\].)+';
		$firstNameRe = "#^/($nameRe)(:($nameRe)|/|$)#";
		$result = false;
		if ( preg_match( $firstNameRe, $searchPath, $matches ) ) {
			$rawName = $matches[1];
			$rawValue = $matches[4];
			$name = preg_replace( '/\\\\(.)/', '\\1', $rawName );
			$value = preg_replace( '/\\\\(.)/', '\\1', $rawValue);
			if ( $name == '...' ) {
				$searchPath = substr( $searchPath, 4 );
				return self::matchDeepSearchPath( $message, $searchPath, $negate );
			} else {
				if ( !is_array( $message ) || !$message[ $name ] ) {
					return $negate;
				}
				if ( $value ) {
					if ( $value[0] == '"' ) { // string regexp match
						$value = substr( $value, 1, -1 );
						$result = preg_match( "/".$value."/", $message[$name] );
					} else {
						$result = ( $value == $message[$name] );
					}
				} else {
					$searchPath = substr( $searchPath, strlen( $matches[0] ) - 1 );
					if ( $searchPath != '/' ) {
						$result = self::matchSearchPath( 
							$message[$name], $searchPath );
					} else {
						$result = true;
					}
				}
			}
			if ( $result ) {
				return !$negate ;
			}
		} else {
			
		}
		return $negate;
	}
	
	protected function matchDeepSearchPath( $message, $searchPath, $negate = false ) {
		$result = self::matchSearchpath( $message, $searchPath );
		if ( $result ) {
			return !$negate;
		}
		foreach ( $message as $key => $subMessage ) {
			$result = self::matchDeepSearchPath( 
				$subMessage, $searchPath );
			if ( $result ) {
				return !$negate;
			}
		}
		return $negate;
	}
	
}
	
?>