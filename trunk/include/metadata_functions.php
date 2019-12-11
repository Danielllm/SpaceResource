<?php
/**
* Metadata related functions
* 
* Functions related to resource metadata in general
* 
* @package ResourceSpace\Includes
*/


/**
* Run FITS on a file and get the output back
* 
* @uses get_utility_path()
* @uses run_command()
* 
* @param string $file_path Physical path to the file
* 
* @return SimpleXMLElement
*/
function runFitsForFile($file_path)
    {
    global $fits_path;

    $fits              = get_utility_path('fits');
    $fits_path_escaped = escapeshellarg($fits_path);
    $file              = escapeshellarg($file_path);

    if(false === $fits)
        {
        trigger_error('FITS library could not be located!');
        }

    putenv("LD_LIBRARY_PATH={$fits_path_escaped}/tools/mediainfo/linux");

    $return = run_command("{$fits} -i {$file} -xc");

    return new SimpleXMLElement($return);
    }


/**
* Get metadata value for a FITS field
* 
* @param SimpleXMLElement $xml  FITS metadata XML
* @param string $fits_field A ResourceSpace specific FITS field mapping which allows ResourceSpace to know exactly where
*                               to look for that value in XML by converting it to an XPath query string.
* Example:
* video.mimeType would point to
* 
* <metadata>
*   <video>
*     [...]
*     <mimeType toolname="MediaInfo" toolversion="0.7.75" status="SINGLE_RESULT">video/quicktime</mimeType>
*     [...]
*   </video>
* </metadata>
* 
* @return string
*/
function getFitsMetadataFieldValue(SimpleXMLElement $xml , $fits_field)
    {
    // IMPORTANT: Not using "fits" namespace (or any for that matter) will yield no results
    // TODO: since there can be multiple namespaces (especially if run with -xc options) we might need to implement the
    // ability to use namespaces directly from RS FITS Field.
    $xml->registerXPathNamespace('fits', 'http://hul.harvard.edu/ois/xml/ns/fits/fits_output');

    // Convert fits field mapping from rs format to namespaced XPath format
    // Example rs field mapping for an xml element value
    //   rs field is one.two.three which converts to an xpath filter of //fits:one/fits:two/fits:three
    // Example rs field mapping for an xml attribute value (attributes are not qualified by the namespace)
    //   rs attribute is one.two.three/@four which converts to an xpath filter of //fits:one/fits:two/fits:three/@four
    $fits_path = explode('.', $fits_field);
    // Reassemble with the namespace
    $fits_filter  = "//fits:".implode('/fits:', $fits_path);

    $result = $xml->xpath($fits_filter);

    if(!isset($result) || false === $result || 0 === count($result))
        {
        return '';
        }

    // First result entry carries the element or attribute value
    if( isset($result[0]) && !is_array($result[0]) )
        {
        return $result[0];
        }

    return '';
    }


/**
* Extract FITS metadata from a file for a specific resource.
* 
* @uses get_resource_data()
* @uses escape_check()
* @uses sql_query()
* @uses runFitsForFile()
* @uses getFitsMetadataFieldValue()
* @uses update_field()
* 
* @param string         $file_path Path to the file from which you will extract FITS metadata
* @param integer|array  $resource  Resource ID or resource array (as returned by get_resource_data())
* 
* @return boolean
*/
function extractFitsMetadata($file_path, $resource)
    {
    if(get_utility_path('fits') === false)
        {
        return false;
        }

    if(!file_exists($file_path))
        {
        return false;
        }

    if(!is_array($resource) && !is_numeric($resource))
        {
        return false;
        }

    if(!is_array($resource) && is_numeric($resource) && 0 < $resource)
        {
        $resource = get_resource_data($resource);
        }

    $resource_type = escape_check($resource['resource_type']);

    // Get a list of all the fields that have a FITS field set
    $rs_fields_to_read_for = sql_query("
           SELECT rtf.ref,
                  rtf.`type`,
                  rtf.`name`,
                  rtf.fits_field
             FROM resource_type_field AS rtf
            WHERE length(rtf.fits_field) > 0
              AND (rtf.resource_type = '{$resource_type}' OR rtf.resource_type = 0)
         ORDER BY fits_field;
    ");

    if(0 === count($rs_fields_to_read_for))
        {
        return false;
        }

    // Run FITS and extract metadata
    $fits_xml            = runFitsForFile($file_path);
    $fits_updated_fields = array();

    foreach($rs_fields_to_read_for as $rs_field)
        {
        $fits_fields = explode(',', $rs_field['fits_field']);

        foreach($fits_fields as $fits_field)
            {
            $fits_field_value = getFitsMetadataFieldValue($fits_xml, $fits_field);

            if('' == $fits_field_value)
                {
                continue;
                }

            update_field($resource['ref'], $rs_field['ref'], $fits_field_value);

            $fits_updated_fields[] = $rs_field['ref'];
            }
        }

    if(0 < count($fits_updated_fields))
        {
        return true;
        }

    return false;
    }


/**
* Check date conforms to "yyyy-mm-dd hh:mm" format or any valid partital of that e.g. yyyy-mm.
* 
* @uses checkDateParts()
* 
* @param string         string form of the date to check
* 
* @return string
*/
function checkDateFormat($date)
    {
    global $lang;

    // Check the format of the date to "yyyy-mm-dd hh:mm"
    if (preg_match("/^([0-9]{4})-([0-9]{2})-([0-9]{2}) ([0-9]{2}):([0-9]{2})$/", $date, $parts))
        {
        return str_replace("%date%", $date, CheckDateParts($parts));
        } 
    // Check the format of the date to "yyyy-mm-dd"
    elseif (preg_match ("/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/", $date, $parts))
        {
        return str_replace("%date%", $date, CheckDateParts($parts));
        } 
    // Check the format of the date to "yyyy-mm" pads with 01 to ensure validity
    elseif (preg_match("/^([0-9]{4})-([0-9]{2})$/", $date, $parts))
        {
        array_push($parts, '01');
        return str_replace("%date%", $date, CheckDateParts($parts));
        } 
    // Check the format of the date to "yyyy" pads with 01 to ensure validity
    elseif (preg_match("/^([0-9]{4})$/", $date, $parts))
        {
        array_push($parts, '01', '01');
        return str_replace("%date%", $date, CheckDateParts($parts));
        } 

    // If it matches nothing return unknown format error
    return str_replace("%date%", $date, $lang["unknown_date_format_error"]);;
    }

/**
* Check datepart conforms to its formatting and error out each section accordingly
* 
* @param array         array of the date parts
* 
* @return string
*/
function checkDateParts($parts)
    {
    global $lang;
    
    // Initialise error list holder
    $invalid_parts = array();
    
    // Check day part
    if (!checkdate('01',$parts[3],'2000'))
        {
        array_push($invalid_parts, 'day');
        } 
    // Check day month
    if (!checkdate($parts[2],'01','2000')) 
        {
        array_push($invalid_parts, 'month');
        } 
    // Check year part
    if (!checkdate('01','01',$parts[1])) 
        {
        array_push($invalid_parts, 'year');
        }
    // Check time part
    if (isset($parts[4]) && isset($parts[5])) 
        {
        if (!strtotime($parts[4] . ':' . $parts[5]))
            {
            array_push($invalid_parts, 'time');
            }
        }

    // No errors found return false
    if(empty($invalid_parts))
        {
        return false;
        } 
    // Return errors found
    else
        {
        return str_replace("%parts%", implode(", ", $invalid_parts), $lang["date_format_error"]);
        }
    }