<?php
/*
 * REST API area privileges ("api-<area>-<action>"). They grant API access
 * to an area without any GUI page; see restapi/areas.inc. A user also needs
 * "WebCfg - System: REST API access" (page-system-restapi-keys) to use a key.
 */

global $priv_list;

require_once('restapi/areas.inc');

foreach (restapi_areas() as $area => $label) {
	$read = restapi_area_privilege($area, 'read');
	$priv_list[$read] = array();
	$priv_list[$read]['name'] = sprintf(gettext('REST API - %s: read'), gettext($label));
	$priv_list[$read]['descr'] = sprintf(gettext('Allow API keys of this user to read %s, without GUI access.'),
	    gettext($label));

	$write = restapi_area_privilege($area, 'write');
	$priv_list[$write] = array();
	$priv_list[$write]['name'] = sprintf(gettext('REST API - %s: write'), gettext($label));
	$priv_list[$write]['descr'] = sprintf(gettext('Allow API keys of this user to read and change %s, without GUI access.'),
	    gettext($label));
	$priv_list[$write]['warn'] = 'standard-warning-root';
}
