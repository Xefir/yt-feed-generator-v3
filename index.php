<?php

$baseurl = 'https://www.googleapis.com/youtube/v3';
$my_key = '';
$username = '';
$nb_entries = 20;
$thumb_quality = 'medium'; // can be 'default', 'medium', 'high', 'standard', 'maxres'

function get_channel_for_user($user)
{
	global $baseurl, $my_key;
	$url = $baseurl . '/channels?part=id&forUsername=' . $user . '&key=' . $my_key;
	$response = @file_get_contents($url);
	$data = json_decode($response, true);
	return $data['items'][0]['id'];
}

function get_playlists($channel)
{
	global $baseurl, $my_key;
	$playlists = array();
	// we have to get the full snippet here, because there is no other way to get the channelId
	// of the channels you're subscribed to. 'id' returns a subscription id, which can only be
	// used to subsequently get the full snippet, so we may as well just get the whole lot up front.
	$url = $baseurl . '/subscriptions?part=snippet&channelId=' . $channel . '&maxResults=50&key=' . $my_key;

	$next_page = '';
	while (true) {
		// we are limited to 50 results. if the user subscribed to more than 50 channels
		// we have to make multiple requests here.
		$response = @file_get_contents($url . $next_page);
		$data = json_decode($response, true);
		$subs = array();
		foreach ($data['items'] as $i) {
			if ($i['kind'] == 'youtube#subscription') {
				$subs[] = $i['snippet']['resourceId']['channelId'];
			}
		}

		// actually getting the channel uploads requires knowing the upload playlist ID, which means
		// another request. luckily we can bulk these 50 at a time.
		$purl = $baseurl . '/channels?part=contentDetails&id=' . implode('%2C', $subs) . '&maxResults=50&key=' . $my_key;
		$response2 = @file_get_contents($purl);
		$data2 = json_decode($response2, true);
		foreach ($data2['items'] as $i2) {
			if (!empty($i2['contentDetails']['relatedPlaylists']['uploads'])) {
				$playlists[] = $i2['contentDetails']['relatedPlaylists']['uploads'];
			}
		}

		if (!empty($data['nextPageToken'])) { // loop until there are no more pages
			$next_page = '&pageToken=' . $data['nextPageToken'];
		} else {
			break;
		}
	}

	return $playlists;
}

function get_playlist_items($playlist)
{
	global $baseurl, $my_key;
	$videos = array();

	// get the last 5 videos uploaded to the playlist
	$url = $baseurl . '/playlistItems?part=contentDetails&playlistId=' . $playlist . '&maxResults=5&key=' . $my_key;
	$response = @file_get_contents($url);
	$data = json_decode($response, true);
	foreach ($data['items'] as $i) {
		if ($i['kind'] == 'youtube#playlistItem') {
			$videos[] = $i['contentDetails']['videoId'];
		}
	}

	return $videos;
}

function get_real_videos($video_ids)
{
	global $baseurl, $my_key;

	$purl = $baseurl . '/videos?part=snippet&id=' . implode('%2C', $video_ids) . '&maxResults=50&key=' . $my_key;
	$response = @file_get_contents($purl);
	$data = json_decode($response, true);

	return $data['items'];
}

// get all upload playlists of subbed channels
$playlists = get_playlists(get_channel_for_user($username));

// get the last 5 items from every playlist
$allitems = array();
foreach ($playlists as $p) {
	$allitems = array_merge($allitems, get_playlist_items($p));
}

// the playlist items don't contain the correct published date, so now
// we have to fetch every video in batches of 50.
$allvids = array();
for ($i = 0; $i < count($allitems); $i = $i + 50) {
	$j = isset($allitems[$i + 50]) ? $i + 50 : (count($allitems) - 1) % 50;
	$rvids = get_real_videos(array_slice($allitems, $i, $j));
	if ($rvids) {
		foreach ($rvids as $r) {
			$allvids[] = $r;
		}
	} else {
		break;
	}
}

// sort them by date
function cmp($a, $b)
{
	$a = strtotime($a['snippet']['publishedAt']);
	$b = strtotime($b['snippet']['publishedAt']);

	if ($a == $b) {
		return 0;
	}
	return ($a > $b) ? -1 : 1;
}

usort($allvids, 'cmp');

// Fix to include CDATA (from http://stackoverflow.com/a/20511976)
class SimpleXMLElementExtended extends SimpleXMLElement
{
	public function addChildWithCDATA($name, $value = null)
	{
		$new_child = $this->addChild($name);

		if (isset($new_child)) {
			$node = dom_import_simplexml($new_child);
			$no = $node->ownerDocument;
			$node->appendChild($no->createCDATASection($value));
		}

		return $new_child;
	}
}

// build the rss
$rss = new SimpleXMLElementExtended('<rss version="2.0"></rss>');
$channel = $rss->addChild('channel');
$channel->title = 'Youtube subscriptions for ' . $username;
$channel->link = 'http://www.youtube.com/';
$channel->description = 'YouTube RSS feed generator by Xefir Destiny ; ported from python ytsubs by ali1234 https://github.com/ali1234/ytsubs';
$atom = $channel->addChild('link', null, 'http://www.w3.org/2005/Atom');
$atom->addAttribute('href', 'http://' . $_SERVER['SERVER_NAME'] . dirname($_SERVER['REQUEST_URI']) . '/rss.xml');
$atom->addAttribute('rel', 'self');

// add the most recent
for ($v = 0; $v < $nb_entries; $v++) {
	$link = 'http://youtube.com/watch?v=' . $allvids[$v]['id'];
	$item = $channel->addChild('item');
	$item->link = $link;
	$item->addChildWithCDATA('title', $allvids[$v]['snippet']['title']);
	$item->addChildWithCDATA('description', '
		<table>
			<tr>
				<td><img src="' . $allvids[$v]['snippet']['thumbnails'][$thumb_quality]['url'] . '" alt="default" /></td>
				<td>' . str_replace("\n", '<br>', htmlentities($allvids[$v]['snippet']['description'])) . '</td>
			</tr>
		</table>');
	$item->guid = $link;
	$item->pubDate = date(DATE_RSS, strtotime($allvids[$v]['snippet']['publishedAt']));
	$item->addChild('dc:creator', $allvids[$v]['snippet']['channelTitle'], 'http://purl.org/dc/elements/1.1/');
}

$rss->saveXML('rss.xml');
header('Content-Type: application/rss+xml');
echo $rss->asXML();
