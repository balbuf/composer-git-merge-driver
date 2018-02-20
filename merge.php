#!/usr/bin/env php
<?php

define('CONFLICT_PLACEHOLDER', '=c=o=n=f=(\d+)=l=i=c=t=');
define('JSON_ENCODE_OPTIONS', JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
// file states
$states = ['ancestor', 'ours', 'theirs'];
// unique object that represents a void
$void = (object) [];
// conflicts we have identified
$conflicts = [];
// options for json encode
$markerLen = $argv[4];

// grab the contents of each file state
foreach ($states as $i => $state) {
	// read and parse the composer file
	$$state = json_decode(file_get_contents($argv[$i + 1]), true);

	// check for malformed json
	if (json_last_error() !== JSON_ERROR_NONE || !is_array($$state)) {
		exit(1);
	}
}

// determine whether an array is assoc
function isAssoc($array) {
	return array_keys($array) !== range(0, count($array) - 1);
}

// create an auto-incrementing conflict placeholder
function conflictPlaceholder($ours, $theirs) {
	global $conflicts;
	$conflicts[] = [$ours, $theirs];
	return str_replace('(\d+)', count($conflicts) - 1, CONFLICT_PLACEHOLDER);
}

// merge a set of assoc arrays
function merge($ancestor, $ours, $theirs) {
	global $states, $void, $conflict;

	// get the unique union of all keys from theirs and ours
	$keys = array_keys($ours + $theirs);

	// reconcile each key
	foreach ($keys as $key) {
		// get the value of this key from each state
		foreach ($states as $state) {
			${rtrim($state, 's') . 'Value'} = is_array($$state) && array_key_exists($key, $$state) ? ${$state}[$key] : $void;
		}

		// same value? or theirs was the same as the ancestor? all good!
		if ($ourValue === $theirValue || $theirValue === $ancestorValue) {
			continue;
		}

		// is their value newer? (i.e. ours is the same as the ancestor)
		if ($ourValue === $ancestorValue) {
			// is this key absent in theirs?
			if ($theirValue === $void) {
				unset($ours[$key]);
			} else {
				$ours[$key] = $theirValue;
			}
		}
		// are both values arrays?
		else if (is_array($ourValue) && is_array($theirValue)) {
			// are the arrays both assoc or both non-assoc?
			if (($isAssoc = isAssoc($ourValue)) === isAssoc($theirValue)) {
				// are they both assoc?
				if ($isAssoc) {
					$ours[$key] = merge($ancestorValue, $ourValue, $theirValue);
				}
				// both non-assoc
				else {
					// @todo provide option to merge these arrays?
					$ours[$key] = conflictPlaceholder($ourValue, $theirValue);
				}
			}
			// one is assoc, the other is non-assoc - that's a conflict!
			else {
				$ours[$key] = conflictPlaceholder($ourValue, $theirValue);
			}
		}
		// differing values - we have a conflict!
		else {
			$ours[$key] = conflictPlaceholder($ourValue, $theirValue);
		}
	}

	return $ours;
}

// special handling for lock files
if (strtolower($argv[5]) === 'composer.lock') {
	$packageKeys = ['packages', 'packages-dev', 'aliases'];

	foreach ($states as $state) {
		// ensure we don't get a conflict on the content hash
		${$state}['content-hash'] = null;

		// convert package array to assoc array, mapping package name to json-encoded package definition
		foreach ($packageKeys as $key) {
			$newArray = [];
			foreach (${$state}[$key] as $package) {
				// @todo this is different for alias
				$newArray[$package['name']] = json_encode($package);
			}
			${$state}[$key] = $newArray;
		}
	}

	// perform the merge
	$merged = merge($ancestor, $ours, $theirs);

	// convert the package arrays back
	foreach ($packageKeys as $key) {
		// sort the packages
		ksort($merged[$key]);

		$newArray = [];
		foreach ($merged[$key] as $package) {
			// if we have a conflict, convert the conflicting values back to package definition arrays
			if (preg_match('/^' . CONFLICT_PLACEHOLDER . '$/', $package, $matches)) {
				$conflicts[$matches[1]] = array_map(function($json) {
					return json_decode($json, true);
				}, $conflicts[$matches[1]]);
				$newArray[] = $package;
			}
			// otherwise just convert the value back to a package definition array
			else {
				$newArray[] = json_decode($package, true);
			}
		}
		$merged[$key] = $newArray;
	}

	// update the content-hash key
	$merged['content-hash'] = (count($conflicts) ? 'Merge conflict!' : 'Auto-merged!') . ' Run `composer update --lock` to regenerate';

	$merged = json_encode($merged, JSON_ENCODE_OPTIONS);
} else {
	$merged = json_encode(merge($ancestor, $ours, $theirs), JSON_ENCODE_OPTIONS);
}

// if we have conflicts, replace the conflict markers with the actual conflicting values
if (count($conflicts)) {
	$merged = preg_replace_callback('/^(\s+)(.+)"' . CONFLICT_PLACEHOLDER . '"(,?)$/m', function ($matches) use ($conflicts, $markerLen) {
		list(, $space, $property, $conflictNum, $comma) = $matches;
		// replace the conflict placeholder with theirs/ours values surrounded by conflict markers
		return str_repeat('<', $markerLen) . " HEAD\n"
			. $space . $property . preg_replace('/\n/', "\n$space", json_encode($conflicts[$conflictNum][1], JSON_ENCODE_OPTIONS)) . $comma . "\n"
			. str_repeat('=', $markerLen) . "\n"
			. $space . $property . preg_replace('/\n/', "\n$space", json_encode($conflicts[$conflictNum][0], JSON_ENCODE_OPTIONS)) . $comma . "\n"
			. str_repeat('>', $markerLen);
	}, $merged);
}

// update the file
file_put_contents($argv[2], $merged . "\n");

// determine exit status based on whether there were conflicts
exit(count($conflicts) ? 1 : 0);
