<?php
    $key = @$_COOKIE['host-key'];

    $sessions_storage_file = 'sessions.json';
    $sessions_storage = json_decode(@file_get_contents($sessions_storage_file), true);

    $db = new mysqli('localhost', 'root', 'root', 'multicinema');

    $fetch = $db->query("SELECT * FROM `sessions` WHERE `session_key` = '".$db->real_escape_string($key)."'");
    if ($key == null || $fetch->num_rows == 0 || $db->real_escape_string($key) != $key) {
        $key_session = hash('sha256', time() . mt_rand(PHP_INT_MIN, PHP_INT_MAX) . 'key' . mt_rand(PHP_INT_MIN, PHP_INT_MAX) . 'gen' . mt_rand(PHP_INT_MIN, PHP_INT_MAX));
        
        $db->query("INSERT INTO `sessions` (`session_key`) VALUES ('$key_session')");

        setcookie('host-key', $key_session, time() + 24 * 7 * 2600);
        
        $key = $key_session;
    }

	if (@$_GET['do'] == 'api') {
		$data = @json_decode(@file_get_contents('php://input'), true) ?? [];

		$result = [
			'success'=> true
        ];
        
		switch (@$_GET['action']) {
            case 'getMyMode':
                $ast = $db->query("SELECT * FROM `sessions` WHERE `session_key` = '" . $db->real_escape_string($key) . "'")->fetch_assoc();
                $result['mode'] = $ast['mode'] ? ($ast['mode'] == 'host' ? 'host' : 'view') : false;
                
                if ($ast['mode'] == 'host') {
                    $result['roomKey'] = $ast['room_key'];
                    $result['url'] = $ast['url'];
                }
                if ($ast['mode'] == 'view' && isset($ast['connected_room_key'])) {
                    $result['connectedRoomKey'] = $ast['connected_room_key'];
                }
                    
                break;
            case 'setMyMode':
                if (isset($data['mode'])) {
                    $db->query("UPDATE `sessions` SET `mode`='" . ($data['mode'] ? 'host' : 'view') . "' WHERE `session_key` = '" . $db->real_escape_string($key) . "'");
                    if ($data['mode']) {
                        $room_len = 8;
                        $room_key = strtoupper(substr($key, mt_rand(0, strlen($key)) - $room_len, $room_len));
                        $db->query("UPDATE `sessions` SET `room_key`='$room_key' WHERE `session_key` = '" . $db->real_escape_string($key) . "'");
                        $result['roomKey'] = $room_key;
                    }
                }
                break;
            case 'connectViewRoom':
                $ast = $db->query("SELECT * FROM `sessions` WHERE `session_key` = '" . $db->real_escape_string($key) . "'")->fetch_assoc();
                if (!($ast['mode'] == 'view') || !isset($data['room_key'])) {
                    $result['success'] = false;
                    $result['error'] = 'Cant connect to Host in Host mode';

                    break;
                }
                $data['room_key'] = strtoupper($data['room_key']);

                if ($db->real_escape_string($data['room_key']) != $data['room_key']) {
                    $result['error'] = 'Irreal Host sent!';
                    $result['success'] = false;
                    break;
                }
                
                $ast = $db->query("SELECT * FROM `sessions` WHERE `mode`='host' AND `room_key`='" . $data['room_key'] . "'");
                if ($ast->num_rows != 0) {
                    $ast = $ast->fetch_assoc();
                    $result['connectedRoomKey'] = $ast['room_key'];
                    $db->query("UPDATE `sessions` SET `connected_room_key`='" . $ast['room_key'] . "' WHERE `session_key` = '" . $db->real_escape_string($key) . "'");
                    break;
                }
                $result['error'] = 'Cant connect to non-exists Host';
                $result['success'] = false;
                break;
            
            case 'checkViewStatus':
                $room_key = $data['room_key'];
                if ($room_key == null || $room_key != $db->real_escape_string($room_key)) {
                    $result['success'] = false;
                    break;
                }

                $map = $db->query("SELECT * FROM `sessions` WHERE `room_key` = '$room_key'");
                if ($map->num_rows != 0) {
                    $map = $map->fetch_assoc();
                    $result['url'] = $map['url'];
                    $result['playing'] = $map['playing'];
                    $result['position'] = $map['position'];




                    break;
                }

                $result['success'] = false;
                break;
            
            case 'runVideo':
                $video_url = $data['video_url'];

                if (!$video_url) {
                    $result['error'] = 'Cant begin empty URL';
                    $result['success'] = false;
                    break;
                }

                $result['url'] = $video_url;

                $db->query("UPDATE `sessions` SET `playing`='0', `position`='0', `url`='" . $db->real_escape_string($video_url) . "' WHERE `session_key` = '" . $db->real_escape_string($key) . "'");

                break;
            case 'sendHostVideoStatus':
                $play = (int)(boolean)@$data['playing'];
                $pos = (float)@$data['position'];
                
                $db->query("UPDATE `sessions` SET `playing`='$play', `position`='$pos' WHERE `session_key` = '" . $db->real_escape_string($key) . "'");
                
                break;
        }

        
		header('Content-Type: application/json');
		file_put_contents($sessions_storage_file, json_encode($sessions_storage));
		die(json_encode($result));
    }
?>
<html>
	<head>
		<style>
		* {
			color: #FFF;
			border-color: #FFF;
			background-color: #262626;
		}
        </style>
        <script src="https://www.youtube.com/iframe_api"></script>
		<script>
        function Video(id) {
            this.driver = ''; // direct / youtube
            this.url = '';
            this.yt_drv = undefined;
            this.element_id = id;
            this.video_element = undefined;
        }
        Video.prototype.videoId = function(url) {
            var id = '';
            var m = url.match(/(youtu\.be\/|youtube\.com\/(watch\?(.*&)?v=|(embed|v)\/))([^\?&"'>]+)/);
            if (url.split('youtu').length > 1 && m && m.length > 0)  {
                id = m.pop();
            }
            return id;
        }
        Video.prototype.isReady = function() {
            if (this.video_element || this.yt_drv) return true;
            return false;
        }
        Video.prototype.setVideo = function(url) {
            var yt = this.videoId(url);
            if (this.driver == 'youtube' && this.yt_drv) {
                this.yt_drv.destroy();
                this.yt_drv = undefined;
            }
            if (this.driver == 'direct' && this.video_element) {
                this.video_element.remove();
                this.video_element = undefined;
            }
            if (yt) {
                this.yt_drv = new YT.Player(this.element_id, {
                    height: '360',
                    width: '640',
                    videoId: yt
                });
                this.driver = 'youtube';
            } else {
                var el = document.getElementById(this.element_id);
                if (el) {
                    this.video_element = createRealElement({
                        tag: 'video',
                        attributes: {
                            src: url,
                            width: '640',
                            height: '360',
                            controls: true
                        }
                    });
                    el.appendChild(this.video_element);
                }
                this.driver = 'direct';
            }
        }
        Video.prototype.play = function() {
            switch (this.driver) {
                case 'youtube':
                    this.yt_drv.playVideo();
                    break;
                case 'direct':
                    this.video_element.play();
                    break;
            }
        }
        Video.prototype.pause = function() {
            switch (this.driver) {
                case 'youtube':
                    this.yt_drv.pauseVideo();
                    break;
                case 'direct':
                    this.video_element.pause();
                    break;
            }
        }
        Video.prototype.isPlaying = function() {
            switch (this.driver) {
                case 'youtube':
                    return this.yt_drv.getPlayerState() == 1;
                case 'direct':
                    return !this.video_element.paused;
            }
        }
        Video.prototype.getVideoTime = function() {
            switch (this.driver) {
                case 'youtube':
                    return this.yt_drv.getCurrentTime();
                case 'direct':
                    return this.video_element.currentTime;
            }
        }
        Video.prototype.setPlaying = function(playing) {
            return playing ? this.play() : this.pause();
        }
        Video.prototype.setVideoTime = function(time) {
            switch (this.driver) {
                case 'youtube':
                    return this.yt_drv.seekTo(time);
                case 'direct':
                    return this.video_element.currentTime = time;
            }
        }

		function request(func, data, callback) {
			var rqst = new XMLHttpRequest();

			rqst.open('POST', '/index.php?do=api&action=' + func, true);
			rqst.send(JSON.stringify(data));

			rqst.onreadystatechange = function() {
				if (this.readyState != 4) return;

				var obj = {};
				try {
					obj = JSON.parse(this.responseText);
				} catch (e) {}

				callback(obj);
			}
		}
		function createRealElement(object) {
			var element = document.createElement(object.tag);
			if (object.style) {
				Object.keys(object.style).forEach(function(styleKey) {
					element.style[styleKey] = object.style[styleKey];
				});
			}
			if (object.data) {
				Object.keys(object.data).forEach(function(dataKey) {
					element.dataset[dataKey] = object.data[dataKey];
				});
			}
			if (object.id) {
				element.id = object.id;
			}
			if (object.attributes) {
				Object.keys(object.attributes).forEach(function(otherKey) {
					element[otherKey] = object.attributes[otherKey];
				});
			}
			if (object.name) {
				element.name = object.name;
			}
			if (object.class) {
				if (typeof object.class == 'string') {
					object.class = [object.class];
				}
				object.class.forEach(function(clazz) {
					element.classList.add(clazz);
				});
			}
			if (object.inner) {
				if (!Array.isArray(object.inner)) {
					object.inner = [object.inner];
				}
				object.inner.forEach(function(inner) {
					element.appendChild(inner);
				});
			}
			if (object.callback) {
				object.callback(element);
			}
			return element;
		};

        var isHost = -1;
        var hostRoomKey = undefined;
        var viewRoomKey = undefined;

        var video_url = undefined;
        var last_video_url = undefined;

        var player;

        function prepare(host, not_send) {
            if (isHost < 0) {
                if (!not_send) {
                    request('setMyMode', {mode: host}, function(r) {
                        if (host) {
                            hostRoomKey = r.roomKey;
                        }
                    });
                }
                isHost = host;
                document.getElementById('select').remove();
            } else {
                return;
            }
        }
        function view() {
            prepare(0);
        }
        function host() {
            prepare(1);
        }

		function join() {
			var groupJoin = document.getElementById('group-join');

			if (groupJoin.value.length != 8) {
				alert('Enter correct room code!');
			} else {
				request('connectViewRoom', {room_key: groupJoin.value}, function(r) {
					if (!r.success) {
						alert(r.error);
						return;
					}
                    viewRoomKey = r.connectedRoomKey;
				})
			}
		}
        
        var video = new Video('player');

        function __main() {
            if (isHost < 0) {
                request('getMyMode', {}, function(r) {
                    if (r.mode == 'host' || r.mode == 'view') {
                        if (r.mode == 'host' && r.roomKey) {
                            hostRoomKey = r.roomKey;
                            if (r.url) {
                                video_url = r.url;
                            }
                        } else if (r.mode == 'view' && r.connectedRoomKey) {
                            viewRoomKey = r.connectedRoomKey;
                        }
                        prepare(r.mode == 'host', true);
                    }
                });
                return;
            }
            if (isHost) {
                var menu = document.getElementById('hostapp');
                if (!menu) {
                    menu = createRealElement({
                        tag: 'div',
                        id: 'hostapp',
                        attributes: {
                            innerHTML: 'Ваш ключ комнаты (Host):<br />' +
                            '<input type="text" id="group-local" readonly="readonly" value="" /><br />'+
                            /*'<input type="checkbox" oninput="sessionset()" id="canplaypause" checked="checked"><label for="canplaypause">Can you viewers play/pause film?</label><br />'+
                            '<input type="checkbox" oninput="sessionset()" id="canrewind" checked="checked"><label for="canrewind">Can you viewers rewind film?</label><br />'+*/
                            'URL: <input type="text" id="url" value="" /><br/>'+
                            '<button onclick="runVideo()">Сменить видео</button><br/><br/>'

                        }
                    });
                    document.body.appendChild(menu);
                }
                if (hostRoomKey) {
                    document.getElementById('group-local').value = hostRoomKey;
                }

                if (video.isReady()) {
                    try {
                        request('sendHostVideoStatus', {
                            playing: video.isPlaying(),
                            position: video.getVideoTime()
                        }, function(r) {

                        });
                    } catch (e) {}
                }

            } else {
                var menu = document.getElementById('viewapp');
                if (!menu) {
                    menu = createRealElement({
                        tag: 'div',
                        id: 'viewapp',
                        attributes: {
                            innerHTML: 'Подключиться к комнате:<br /><input type="text" id="group-join" /><button onclick="join()">Connect</button><br />'
                        }
                    });
                    document.body.appendChild(menu);
                }
                if (viewRoomKey) {
                    if (!document.getElementById('connected')) {
                        menu.innerHTML = '';
                        menu.appendChild(createRealElement({
                            tag: 'div',
                            id: 'connected',
                            inner: [
                                createRealElement({
                                    tag: 'span',
                                    attributes: {
                                        innerText: 'Подключен к комнате:'
                                    }
                                }),
                                createRealElement({
                                    tag: 'br',
                                }),
                                createRealElement({
                                    tag: 'input',
                                    attributes: {
                                        value: viewRoomKey,
                                        readOnly: true
                                    }
                                })
                            ]
                        }));
                    }

                    request('checkViewStatus', {room_key: viewRoomKey}, function(r) {
                        if (!video_url || video_url != r.url) {
                            video_url = r.url;
                        }
                        if (video.isReady()) {
                            try {
                                var playing = video.isPlaying();
                                var position = video.getVideoTime();
                                if (playing != r.playing) {
                                    if (playing) {
                                        video.pause();
                                    } else {
                                        video.play();
                                    }
                                } 
                                if (Math.abs(position - r.position) > 5) {
                                    video.setVideoTime(r.position);
                                }
                            } catch(e) {}
                        }
                    });
                }
            }
            if (last_video_url != video_url && video_url) {
                last_video_url = video_url;
                
                video.setVideo(video_url);

                var url_holder = document.getElementById('url');
                if (url_holder) {
                    url_holder.value = video_url;
                }
            }
        }
        function runVideo() {
            request('runVideo', {
                video_url: document.getElementById('url').value
            }, function(r) {
                video.setVideo(r.url);
            });
        }

        function main() {
            __main();
            setTimeout(function() {
                main();
            }, 100);
        }
        main();
		</script>
        <title>Films Co-View</title>
	</head>
	<body>
        <div id="select">
            Выберите режим совместного просмотра<br/>
            <button onclick="host()">Хост</button> <button onclick="view()">Зритель</button>
        </div>
        <div id="player"></div>
	</body>
</html>