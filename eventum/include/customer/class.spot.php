<?php
/* vim: set expandtab tabstop=4 shiftwidth=4: */
// +----------------------------------------------------------------------+
// | Eventum - Issue Tracking System                                      |
// +----------------------------------------------------------------------+
// | Copyright (c) 2003, 2004 MySQL AB                                    |
// |                                                                      |
// | This program is free software; you can redistribute it and/or modify |
// | it under the terms of the GNU General Public License as published by |
// | the Free Software Foundation; either version 2 of the License, or    |
// | (at your option) any later version.                                  |
// |                                                                      |
// | This program is distributed in the hope that it will be useful,      |
// | but WITHOUT ANY WARRANTY; without even the implied warranty of       |
// | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the        |
// | GNU General Public License for more details.                         |
// |                                                                      |
// | You should have received a copy of the GNU General Public License    |
// | along with this program; if not, write to:                           |
// |                                                                      |
// | Free Software Foundation, Inc.                                       |
// | 59 Temple Place - Suite 330                                          |
// | Boston, MA 02111-1307, USA.                                          |
// +----------------------------------------------------------------------+
// | Authors: Jo�o Prado Maia <jpm@mysql.com>                             |
// +----------------------------------------------------------------------+
//
// @(#) $Id: s.class.status.php 1.5 04/01/09 05:04:10-00:00 jpradomaia $
//

$customer_db = false;

define("SPOT_CUSTOMER_EXPIRATION_OFFSET", 14);

// Constants used when returning list of customers
define("SPOT_CUSTOMER_OPTION_INNODB_SUPPORT", 11);
define("SPOT_CUSTOMER_OPTION_NO_INNODB_SUPPORT", 12);


class Spot_Customer_Backend
{
    function connect()
    {
        $dsn = array(
            'phptype'  => "mysql",
            'hostspec' => "localhost",
            'database' => "spot",
            'username' => "root",
            'password' => ""
        );
        $GLOBALS['customer_db'] = DB::connect($dsn);
    }


    function getName()
    {
        return "spot";
    }

    function usesSupportLevels()
    {
        return true;
    }


    /**
     * Method used to get the support contract status for a given customer.
     *
     * @access  public
     * @param   integer $customer_id The customer ID
     * @return  string The support contract status
     */
    function getContractStatus($customer_id)
    {
        static $returns;

        // poor man's caching system...
        if (!empty($returns[$customer_id])) {
            return $returns[$customer_id];
        }

        $stmt = "SELECT
                    UNIX_TIMESTAMP(enddate)
                 FROM
                    support
                 WHERE
                    cust_no=$customer_id
                 ORDER BY
                    support_no DESC
                 LIMIT
                    0, 1";
        $res = $GLOBALS["customer_db"]->getOne($stmt);
        if (PEAR::isError($res)) {
            Error_Handler::logError(array($res->getMessage(), $res->getDebugInfo()), __FILE__, __LINE__);
            $status = 'expired';
        } else {
            // if we cannot find a support entry for this customer, he is 'expired'
            if (empty($res)) {
                $status = 'expired';
            } else {
                $current_gmt_ts = Date_API::getCurrentUnixTimestampGMT();
                $grace_period_offset = $this->_getExpirationOffset() * DAY;
                $cutoff_ts = $res + $grace_period_offset;
                if ($current_gmt_ts < $res) {
                    $status = 'active';
                } else {
                    if (($current_gmt_ts >= $res) && ($current_gmt_ts <= $cutoff_ts)) {
                        $status = 'in_grace_period';
                    } else {
                        $status = 'expired';
                    }
                }
            }
        }

        $returns[$customer_id] = $status;
        return $status;
    }


    /**
     * Retrieves the customer titles associated with the given list of issues.
     *
     * @access  public
     * @param   array $result The list of issues
     * @see     Issue::getListing()
     */
    function getCustomerTitlesByIssues(&$result)
    {
        $ids = array();
        for ($i = 0; $i < count($result); $i++) {
            if (!empty($result[$i]["iss_customer_id"])) {
                $ids[] = $result[$i]["iss_customer_id"];
            }
        }
        $ids = array_unique(array_values($ids));
        if (count($ids) == 0) {
            return false;
        }
        $ids = implode(", ", $ids);
        $stmt = "SELECT
                    cust_no,
                    name
                 FROM
                    cust_entity
                 WHERE
                    cust_no IN ($ids)";
        $titles = $GLOBALS["customer_db"]->getAssoc($stmt);
        if (PEAR::isError($titles)) {
            Error_Handler::logError(array($titles->getMessage(), $titles->getDebugInfo()), __FILE__, __LINE__);
        } else {
            // now populate the $result variable again
            for ($i = 0; $i < count($result); $i++) {
                @$result[$i]['customer_title'] = $titles[$result[$i]['iss_customer_id']];
            }
        }
    }


    /**
     * Method used to get the details of the given customer.
     *
     * @access  public
     * @param   integer $customer_id The customer ID
     * @return  array The customer details
     */
    function getDetails($customer_id)
    {
        static $returns;

        // poor man's caching system...
        if (!empty($returns[$customer_id])) {
            return $returns[$customer_id];
        }

        $stmt = "SELECT
                    A.name,
                    B.support_no,
                    B.order_row_no,
                    CONCAT(C.customer_type, ' ', C.level, ' Support') AS support_level,
                    B.enddate AS expiration_date,
                    CONCAT(YEAR(B.startdate), '-', B.support_id) AS contract_id
                 FROM
                    cust_entity A,
                    support B,
                    support_type C
                 WHERE
                    A.cust_no=$customer_id AND
                    B.cust_no=A.cust_no AND
                    B.support_type_no=C.support_type_no AND
                    B.startdate <= NOW()
                 ORDER BY
                    B.enddate DESC
                 LIMIT
                    0, 1";
        $res = $GLOBALS["customer_db"]->getRow($stmt, DB_FETCHMODE_ASSOC);
        if (PEAR::isError($res)) {
            Error_Handler::logError(array($res->getMessage(), $res->getDebugInfo()), __FILE__, __LINE__);
            return array();
        } else {
            if (empty($res)) {
                return array();
            } else {
                list($is_per_incident, $options) = $this->getSupportOptions($res['support_no']);
                list($account_manager, ) = $this->getSalesAccountManager($customer_id);
                $returns[$customer_id] = array(
                    'support_no'        => $res['support_no'],
                    'sales_person'      => $this->getSalesPerson($customer_id, $res['order_row_no']),
                    'account_manager'   => $account_manager,
                    'customer_name'     => $res['name'],
                    'contract_id'       => $res['contract_id'],
                    'support_level'     => $res['support_level'],
                    'support_options'   => @implode(", ", $options),
                    'support_exp_date'  => $res['expiration_date'],
                    'note'              => Customer::getNoteDetailsByCustomer($customer_id),
                    'is_per_incident'   => $is_per_incident
                );
                return $returns[$customer_id];
            }
        }
    }


    /**
     * Returns the list of support options associated with a given support
     * contract ID.
     *
     * @access  public
     * @param   integer $support_no The support contract ID
     * @return  array The list of support options
     */
    function getSupportOptions($support_no)
    {
        // get the extra options for this support contract
        $stmt = "SELECT
                    B.descript,
                    A.contract_type,
                    A.parameter
                 FROM
                    support_extra A,
                    pl_extra B
                 WHERE
                    A.support_no=$support_no AND
                    A.pl_extra_no=B.pl_extra_no
                 ORDER BY
                    B.descript ASC";
        $extra = $GLOBALS["customer_db"]->getAll($stmt, DB_FETCHMODE_ASSOC);
        $extra_options = array();
        $is_per_incident = false;
        for ($i = 0; $i < count($extra); $i++) {
            if ($extra[$i]['contract_type'] == 'perIncident') {
                // get the current usage and the limit
                $incidents_left = ((integer) $extra[$i]['parameter']) - $this->getIncidentUsage($support_no);
                $extra_options[] = $extra[$i]['descript'] . ' (Total: ' . $extra[$i]['parameter'] . '; Left: ' . $incidents_left . ')';
                $is_per_incident = true;
            } else {
                $extra_options[] = $extra[$i]['descript'];
            }
        }
        return array(
            $is_per_incident,
            $extra_options
        );
    }


    /**
     * Returns the total of incidents already redeemed in the given support
     * contract ID.
     *
     * @access  public
     * @param   integer $support_no The support contract ID
     * @return  integer The total of incidents already redeemed
     */
    function getIncidentUsage($support_no)
    {
        $stmt = "SELECT
                    COUNT(*)
                 FROM
                    support_issue
                 WHERE
                    support_no=$support_no";
        return $GLOBALS["customer_db"]->getOne($stmt);
    }


    /**
     * Checks whether the active per-incident contract associated with the given
     * customer ID has any incidents available to be redeemed.
     *
     * @access  public
     * @param   integer $customer_id The customer ID
     * @return  boolean
     */
    function hasIncidentsLeft($customer_id)
    {
        $details = $this->getDetails($customer_id);
        $redeemed_incidents = $this->getIncidentUsage($details['support_no']);
        $total_incidents = $this->getTotalIncidents($details['support_no']);
        $incidents_left = ((integer) $total_incidents) - ((integer) $redeemed_incidents);
        if ($incidents_left > 0) {
            return true;
        } else {
            return false;
        }
    }


    /**
     * Checks whether the active contract associated with the given customer ID
     * is a per-incident contract or not.
     *
     * @access  public
     * @param   integer $customer_id The customer ID
     * @return  boolean
     */
    function hasPerIncidentContract($customer_id)
    {
        $details = $this->getDetails($customer_id);
        if ($details['is_per_incident']) {
            return true;
        } else {
            return false;
        }
    }


    /**
     * Returns the total number of allowed incidents for the given support
     * contract ID.
     *
     * @access  public
     * @param   integer $support_no The support contract ID
     * @return  integer The total number of incidents
     */
    function getTotalIncidents($support_no)
    {
        $stmt = "SELECT
                    parameter
                 FROM
                    support_extra
                 WHERE
                    support_no=$support_no AND
                    contract_type='perIncident'";
        $res = $GLOBALS["customer_db"]->getOne($stmt);
        if (PEAR::isError($res)) {
            Error_Handler::logError(array($res->getMessage(), $res->getDebugInfo()), __FILE__, __LINE__);
            return 0;
        } else {
            return $res;
        }
    }



    /**
     * Returns the name of the sales account manager of the given customer ID.
     *
     * @access  public
     * @param   integer $customer_id The customer ID
     * @return  string The name of the sales account manager
     */
    function getSalesAccountManager($customer_id)
    {
        $stmt = "SELECT
                     A.name,
                     A.email
                 FROM
                     user A,
                     cust_sper B
                 WHERE
                     A.user_no=B.user_no AND
                     B.cust_no=$customer_id AND
                     B.cs_role_no=1";
        return $GLOBALS["customer_db"]->getRow($stmt);
    }


    /**
     * Returns the name of the sales person who sold the given order to the 
     * customer ID.
     *
     * @access  public
     * @param   integer $customer_id The customer ID
     * @param   integer $order_id The order ID
     * @return  string The name of the sales person
     */
    function getSalesPerson($customer_id, $order_id)
    {
        $stmt = "SELECT
                     A.name
                 FROM
                     user A,
                     support B,
                     order_row C,
                     order_head D
                 WHERE
                     A.user_no=D.our_ref AND
                     B.order_row_no=C.order_row_no AND
                     C.order_no=D.order_no AND
                     B.order_row_no=$order_id AND
                     B.cust_no=$customer_id";
        return $GLOBALS["customer_db"]->getOne($stmt);
    }


    /**
     * Checks whether the given issue ID was marked as a redeemed incident or
     * not.
     *
     * @access  public
     * @param   integer $issue_id The issue ID
     * @return  boolean
     */
    function isRedeemedIncident($issue_id)
    {
        $details = $this->getDetails(Issue::getCustomerID($issue_id));
        $stmt = "SELECT
                    COUNT(*)
                 FROM
                    support_issue
                 WHERE
                    support_no=" . $details['support_no'] . " AND
                    iss_id=$issue_id";
        $res = $GLOBALS["customer_db"]->getOne($stmt);
        if (PEAR::isError($res)) {
            Error_Handler::logError(array($res->getMessage(), $res->getDebugInfo()), __FILE__, __LINE__);
            return false;
        } else {
            if ($res > 0) {
                return true;
            } else {
                return false;
            }
        }
    }


    /**
     * Method used to send a notice to the MySQL sales team and the
     * customer contact about the per-incident limit being reached.
     *
     * @access  public
     * @param   integer $contact_id The customer contact ID
     * @param   integer $customer_id The customer ID
     * @return  void
     */
    function sendIncidentLimitNotice($contact_id, $customer_id)
    {
        $type = 'incident_limit_reached_customer';
        // two emails, add the sales@ blurb only when sending the email to the sales team
        list($contact_email, $void, $contact_name) = $this->getContactLoginDetails($contact_id);
        $to = Mail_API::getFormattedName($contact_name, $contact_email);
        $emails = array(
            'customer' => $to,
            'sales'    => '"MySQL Sales Team" <sales@mysql.com>'
        );

        $data = $this->getDetails($customer_id);
        $company_name = $data['customer_name'];

        foreach ($emails as $email_type => $to) {
            if ($email_type == 'sales') {
                $show_sales_blurb = true;
            } else {
                $show_sales_blurb = false;
            }
            // open text template
            $tpl = new Template_API;
            $tpl->setTemplate("customer/" . $this->getName() . "/notifications/" . $type . '.tpl.text');
            $tpl->bulkAssign(array(
                "data"             => $data,
                "show_sales_blurb" => $show_sales_blurb
            ));
            $text_message = $tpl->getTemplateContents();

            @include_once(APP_PEAR_PATH . 'Mail/mime.php');
            $setup = Mail_API::getSMTPSettings();
            $headers['To'] = $to;
            $headers['From'] = $setup["from"];
            $headers['Subject'] = "Incident Report Attempt by Support Customer (" . $company_name . ")";
            $mime = new Mail_mime("\r\n");
            $hdrs = $mime->headers($headers);
            Mail_Queue::add($to, $hdrs, $text_message, 1);
        }
    }


    /**
     * Method used to get an associative array of all companies
     * available in Spot, in a format of customer ID => company name.
     *
     * @access  public
     * @return  array The associative array of companies
     */
    function getAssocList()
    {
        $stmt = "SELECT
                    A.cust_no,
                    A.name
                 FROM
                    cust_entity A,
                    support B
                 WHERE
                    A.cust_type='C' AND
                    A.cust_no=B.cust_no AND
                    B.status <> 'Cancelled' AND
                    NOW() <= (B.enddate + INTERVAL " . $this->_getExpirationOffset() . " DAY)
                 ORDER BY
                    A.name ASC";
        $res = $GLOBALS["customer_db"]->getAssoc($stmt);
        if (PEAR::isError($res)) {
            Error_Handler::logError(array($res->getMessage(), $res->getDebugInfo()), __FILE__, __LINE__);
            return "";
        } else {
            return $res;
        }
    }


    /**
     * Method used to get the customer login grace period (number of days).
     *
     * @access  public
     * @return  integer The customer login grace period
     */
    function _getExpirationOffset()
    {
        return SPOT_CUSTOMER_EXPIRATION_OFFSET;
    }


    /**
     * Method used to get the customer names for the given customer id.
     *
     * @access  public
     * @param   integer $customer_id The customer ID
     * @return  string The customer name
     */
    function getTitle($customer_id)
    {
        $stmt = "SELECT
                    A.name
                 FROM
                    cust_entity A,
                    support B
                 WHERE
                    A.cust_type='C' AND
                    A.cust_no=$customer_id AND
                    A.cust_no=B.cust_no AND
                    B.status <> 'Cancelled' AND
                    NOW() <= (B.enddate + INTERVAL " . $this->_getExpirationOffset() . " DAY)";
        $res = $GLOBALS["customer_db"]->getOne($stmt);
        if (PEAR::isError($res)) {
            Error_Handler::logError(array($res->getMessage(), $res->getDebugInfo()), __FILE__, __LINE__);
            return "";
        } else {
            return $res;
        }
    }


    /**
     * Method used to get an associative array of the customer names
     * for the given list of customer ids.
     *
     * @access  public
     * @param   array $customer_ids The list of customers
     * @return  array The associative array of customer id => customer name
     */
    function getTitles($customer_ids)
    {
        $items = implode(", ", $customer_ids);
        $stmt = "SELECT
                    A.cust_no,
                    A.name
                 FROM
                    cust_entity A,
                    support B
                 WHERE
                    A.cust_type='C' AND
                    A.cust_no IN ($items) AND
                    A.cust_no=B.cust_no AND
                    B.status <> 'Cancelled' AND
                    NOW() <= (B.enddate + INTERVAL " . $this->_getExpirationOffset() . " DAY)";
        $res = $GLOBALS["customer_db"]->getAssoc($stmt);
        if (PEAR::isError($res)) {
            Error_Handler::logError(array($res->getMessage(), $res->getDebugInfo()), __FILE__, __LINE__);
            return "";
        } else {
            return $res;
        }
    }


    /**
     * Method used to get the list of email addresses associated with the 
     * contacts of a given customer.
     *
     * @access  public
     * @param   integer $customer_id The customer ID
     * @return  array The list of email addresses
     */
    function getContactEmailAssocList($customer_id)
    {
        // join with cnt_support to get the contacts that are allowed in this support contract
        $stmt = "SELECT
                    C.eaddress_code,
                    CONCAT(A.name2, ' ', A.name, ' &lt;', C.eaddress_code, '&gt;') AS name
                 FROM
                    cust_entity A,
                    cust_role B,
                    eaddress C,
                    eaddress_type D,
                    support E,
                    cnt_support F
                 WHERE
                    E.cust_no=B.up_cust_no AND
                    F.support_no=E.support_no AND
                    F.cust_no=A.cust_no AND
                    A.cust_type='P' AND
                    A.cust_no=B.cust_no AND
                    B.up_cust_no=$customer_id AND
                    A.cust_no=C.cust_no AND
                    C.eaddress_type_no=D.eaddress_type_no AND
                    D.descript='email' AND
                    E.status <> 'Cancelled' AND
                    NOW() <= (E.enddate + INTERVAL " . $this->_getExpirationOffset() . " DAY)";
        $res = $GLOBALS["customer_db"]->getAssoc($stmt);
        if (PEAR::isError($res)) {
            Error_Handler::logError(array($res->getMessage(), $res->getDebugInfo()), __FILE__, __LINE__);
            return "";
        } else {
            return $res;
        }
    }


    /**
     * Method used to get the Spot customer and customer contact IDs associated
     * with a given list of email addresses.
     *
     * @access  public
     * @param   array $emails The list of email addresses
     * @return  array The customer and customer contact ID
     */
    function getCustomerIDByEmails($emails)
    {
        // this will get called by the download email script to 
        // see which customer is associated with any of those email addresses
        $stmt = "SELECT
                    C.cust_no AS contact_id,
                    C.up_cust_no AS customer_id
                 FROM
                    eaddress A,
                    eaddress_type B,
                    cust_role C
                 WHERE
                    C.cust_no=A.cust_no AND
                    A.eaddress_type_no=B.eaddress_type_no AND
                    B.descript='email' AND
                    A.eaddress_code IN ('" . implode("', '", $emails) . "')
                 LIMIT
                    0, 1";
        $res = $GLOBALS["customer_db"]->getRow($stmt, DB_FETCHMODE_ASSOC);
        if (PEAR::isError($res)) {
            Error_Handler::logError(array($res->getMessage(), $res->getDebugInfo()), __FILE__, __LINE__);
            return array(0, 0);
        } else {
            if (empty($res)) {
                return array(0, 0);
            } else {
                return array($res['customer_id'], $res['contact_id']);
            }
        }
    }


    /**
     * Method used to get the overall statistics of issues in the system for a
     * given customer.
     *
     * @access  public
     * @param   integer $customer_id The customer ID
     * @return  array The customer related issue statistics
     */
    function getOverallStats($customer_id)
    {
        // don't count customer notes, emails, phone calls
        $stmt = "SELECT
                    iss_id,
                    sta_is_closed
                 FROM
                    " . APP_DEFAULT_DB . "." . APP_TABLE_PREFIX . "issue
                 LEFT JOIN
                    " . APP_DEFAULT_DB . "." . APP_TABLE_PREFIX . "status
                 ON
                    iss_sta_id=sta_id
                 WHERE
                    iss_customer_id=$customer_id";
        $res = $GLOBALS["db_api"]->dbh->getAssoc($stmt);
        if ((PEAR::isError($res)) || (empty($res)) || (count($res) == 0)) {
            return array(
                'total_issues'           => 0,
                'total_open'             => 0,
                'total_closed'           => 0,
                'total_persons'          => 0,
                'total_emails'           => 0,
                'total_calls'            => 0,
                'average_first_response' => 0,
                'average_close'          => 0
            );
        } else {
            $issues = array();
            $open = 0;
            $closed = 0;
            foreach ($res as $issue_id => $status) {
                $issues[] = $issue_id;
                if (empty($status)) {
                    $open++;
                } else {
                    $closed++;
                }
            }
        }

        // get the list of distinct persons from the notification 
        // list, phone support and notes tables
        $stmt = "SELECT
                    iss_id,
                    sub_usr_id,
                    not_usr_id,
                    phs_usr_id
                 FROM
                    " . APP_DEFAULT_DB . "." . APP_TABLE_PREFIX . "issue
                 LEFT JOIN
                    " . APP_DEFAULT_DB . "." . APP_TABLE_PREFIX . "note
                 ON
                    not_iss_id=iss_id
                 LEFT JOIN
                    " . APP_DEFAULT_DB . "." . APP_TABLE_PREFIX . "phone_support
                 ON
                    phs_iss_id=iss_id
                 LEFT JOIN
                    " . APP_DEFAULT_DB . "." . APP_TABLE_PREFIX . "subscription
                 ON
                    sub_iss_id=iss_id AND
                    sub_usr_id <> 0 AND
                    sub_usr_id IS NOT NULL
                 LEFT JOIN
                    " . APP_DEFAULT_DB . "." . APP_TABLE_PREFIX . "subscription_type
                 ON
                    sbt_sub_id=sub_id AND
                    sbt_type='emails'
                 WHERE
                    iss_customer_id=$customer_id";
        $res = $GLOBALS["db_api"]->dbh->getAll($stmt, DB_FETCHMODE_ASSOC);
        $persons = array();
        for ($i = 0; $i < count($res); $i++) {
            if ((!empty($res[$i]['sub_usr_id'])) && (!in_array($res[$i]['sub_usr_id'], $persons))) {
                $persons[] = $res[$i]['sub_usr_id'];
            }
            if ((!empty($res[$i]['not_usr_id'])) && (!in_array($res[$i]['not_usr_id'], $persons))) {
                $persons[] = $res[$i]['not_usr_id'];
            }
            if ((!empty($res[$i]['phs_usr_id'])) && (!in_array($res[$i]['phs_usr_id'], $persons))) {
                $persons[] = $res[$i]['phs_usr_id'];
            }
        }

        // get list of staff emails
        $stmt = "SELECT
                    usr_email
                 FROM
                    " . APP_DEFAULT_DB . "." . APP_TABLE_PREFIX . "user,
                    " . APP_DEFAULT_DB . "." . APP_TABLE_PREFIX . "project_user,
                    " . APP_DEFAULT_DB . "." . APP_TABLE_PREFIX . "issue
                 WHERE
                    pru_usr_id=usr_id AND
                    pru_prj_id=iss_prj_id AND
                    iss_id=$issue_id AND
                    usr_role <> " . User::getRoleID('Customer');
        $staff_emails = $GLOBALS["db_api"]->dbh->getCol($stmt);

        $stmt = "SELECT
                    sup_from
                 FROM
                    " . APP_DEFAULT_DB . "." . APP_TABLE_PREFIX . "support_email
                 WHERE
                    sup_iss_id IN (" . implode(", ", $issues) . ")";
        $emails = $GLOBALS["db_api"]->dbh->getCol($stmt);
        $total_emails = 0;
        foreach ($emails as $address) {
            $email = strtolower(Mail_API::getEmailAddress($address));
            $staff_emails = array_map('strtolower', $staff_emails);
            if (@in_array($email, $staff_emails)) {
                $total_emails++;
            }
        }

        $stmt = "SELECT
                    COUNT(*)
                 FROM
                    " . APP_DEFAULT_DB . "." . APP_TABLE_PREFIX . "phone_support
                 WHERE
                    phs_iss_id IN (" . implode(", ", $issues) . ")";
        $calls = $GLOBALS["db_api"]->dbh->getOne($stmt);

        $stmt = "SELECT
                    AVG(UNIX_TIMESTAMP(iss_first_response_date) - UNIX_TIMESTAMP(iss_created_date)) AS first_response_time
                 FROM
                    " . APP_DEFAULT_DB . "." . APP_TABLE_PREFIX . "issue
                 WHERE
                    iss_id IN (" . implode(", ", $issues) . ")";
        $avg_first_response = $GLOBALS["db_api"]->dbh->getOne($stmt);
        if (!empty($avg_first_response)) {
            // format the average into a number of minutes
            $avg_first_response = $avg_first_response / 60;
        }

        $stmt = "SELECT
                    AVG(UNIX_TIMESTAMP(iss_closed_date) - UNIX_TIMESTAMP(iss_created_date)) AS closed_time
                 FROM
                    " . APP_DEFAULT_DB . "." . APP_TABLE_PREFIX . "issue
                 WHERE
                    iss_id IN (" . implode(", ", $issues) . ")";
        $avg_close = $GLOBALS["db_api"]->dbh->getOne($stmt);
        if (!empty($avg_close)) {
            // format the average into a number of minutes
            $avg_close = $avg_close / 60;
        }

        return array(
            'total_issues'           => count($issues),
            'total_open'             => $open,
            'total_closed'           => $closed,
            'total_persons'          => count($persons),
            'total_emails'           => $total_emails,
            'total_calls'            => (integer) $calls,
            'average_first_response' => Misc::getFormattedTime($avg_first_response),
            'average_close'          => Misc::getFormattedTime($avg_close)
        );
    }


    /**
     * Method used to build the overall customer profile from the information
     * stored in Spot.
     *
     * @access  public
     * @param   integer $usr_id The Eventum user ID
     * @return  array The customer profile information
     */
    function getProfile($usr_id)
    {
        $customer_id = User::getCustomerID($usr_id);
        $contact_id = User::getCustomerContactID($usr_id);
        // get the information about the company
        // - company name
        // - support level
        $contract = $this->getContractDetails($contact_id);

        // - street addresses (*-1)
        $stmt = "SELECT
                    line1,
                    line2,
                    line3,
                    line4,
                    state_code,
                    postcode,
                    city,
                    country_code
                 FROM
                    address
                 WHERE
                    cust_no=$customer_id";
        $res = $GLOBALS["customer_db"]->getAll($stmt, DB_FETCHMODE_ASSOC);
        $company_addresses = array();
        if (!empty($res)) {
            for ($i = 0; $i < count($res); $i++) {
                $lines = array();
                $lines[] = $res[$i]['line1'];
                if (!empty($res[$i]['line2'])) {
                    $lines[] = $res[$i]['line2'];
                }
                if (!empty($res[$i]['line3'])) {
                    $lines[] = $res[$i]['line3'];
                }
                if (!empty($res[$i]['line4'])) {
                    $lines[] = $res[$i]['line4'];
                }
                if (!empty($res[$i]['city'])) {
                    $line = $res[$i]['city'];
                    if (!empty($res[$i]['state_code'])) {
                        $line .= ", " . $res[$i]['state_code'];
                    }
                    if (!empty($res[$i]['postcode'])) {
                        $line .= " " . $res[$i]['postcode'];
                    }
                    $lines[] = $line;
                }
                if (!empty($res[$i]['country_code'])) {
                    $lines[] = $res[$i]['country_code'];
                }
                $company_addresses[] = implode("\n", $lines);
            }
        }
        // - email addresses (*-1)
        // - phone numbers (*-1)
        $stmt = "SELECT
                    A.eaddress_code,
                    B.descript
                 FROM
                    eaddress A,
                    eaddress_type B,
                    cust_entity C
                 WHERE
                    A.cust_no=$customer_id AND
                    A.cust_no=C.cust_no AND
                    C.cust_type='C' AND
                    A.eaddress_type_no=B.eaddress_type_no AND
                    B.descript IN ('email', 'telephone')";
        $res = $GLOBALS["customer_db"]->getAssoc($stmt);
        $company_emails = array();
        $company_phones = array();
        foreach ($res as $value => $type) {
            if ($type == 'email') {
                $company_emails[] = $value;
            } else {
                $company_phones[] = $value;
            }
        }

        // get the contacts
        $stmt = "SELECT
                    A.cust_no,
                    CONCAT(A.name, ', ', A.name2) AS full_name
                 FROM
                    cust_entity A,
                    cust_role B,
                    cnt_support C,
                    support D
                 WHERE
                    A.cust_type='P' AND
                    A.cust_no=B.cust_no AND
                    B.up_cust_no=$customer_id AND
                    D.cust_no=B.up_cust_no AND
                    C.support_no=D.support_no AND
                    C.cust_no=A.cust_no AND
                    D.status <> 'Cancelled' AND
                    NOW() <= (D.enddate + INTERVAL " . $this->_getExpirationOffset() . " DAY)";
        $res = $GLOBALS["customer_db"]->getAssoc($stmt);
        $contacts = array();
        if (!empty($res)) {
            foreach ($res as $contact_id => $name) {
                $contacts[$contact_id] = array(
                    "name"       => $name,
                    "telephones" => array(),
                    "emails"     => array()
                );
            }
            $contact_ids = array_keys($res);
            // - phone numbers
            // - email addresses
            $stmt = "SELECT
                        A.cust_no,
                        A.eaddress_code,
                        B.descript
                     FROM
                        eaddress A,
                        eaddress_type B
                     WHERE
                        A.eaddress_type_no=B.eaddress_type_no AND
                        B.descript IN ('email', 'telephone') AND
                        A.cust_no IN (" . implode(", ", $contact_ids) . ")";
            $tres = $GLOBALS["customer_db"]->getAll($stmt, DB_FETCHMODE_ASSOC);
            if (!empty($tres)) {
                for ($i = 0; $i < count($tres); $i++) {
                    if ($tres[$i]['descript'] == 'telephones') {
                        $contacts[$tres[$i]['cust_no']]['telephones'][] = $tres[$i]['eaddress_code'];
                    } else {
                        $contacts[$tres[$i]['cust_no']]['emails'][] = $tres[$i]['eaddress_code'];
                    }
                }
            }
        }

        // - standard contract version
        // - support faq version
        $stmt = "SELECT
                    A.version AS contract_version,
                    B.version AS faq_version
                 FROM
                    support_faq A,
                    support_agr B,
                    support C
                 WHERE
                    C.cust_no=$customer_id AND
                    A.support_faq_no=C.support_faq_no AND
                    B.support_agr_no=C.support_agr_no AND
                    C.status <> 'Cancelled' AND
                    NOW() <= (C.enddate + INTERVAL " . $this->_getExpirationOffset() . " DAY)";
        $versions = $GLOBALS["customer_db"]->getRow($stmt, DB_FETCHMODE_ASSOC);

        return array(
            "company_name"    => $contract['company_name'],
            "addresses"       => $company_addresses,
            "email_addresses" => $company_emails,
            "telephones"      => $company_phones,
            "support_level"   => $contract['support_level'],
            "support_options" => $contract['support_options'],
            "contract_id"     => $contract['contract_id'],
            "expiration_date" => $contract['expiration_date'],
            "contacts"        => $contacts,
            "versions"        => $versions
        );
    }


    /**
     * Method used to get the contract details for a given customer contact.
     *
     * @access  public
     * @param   integer $contact_id The customer contact ID
     * @return  array The customer contract details
     */
    function getContractDetails($contact_id, $restrict_expiration = TRUE)
    {
        $details = $this->getContactDetails($contact_id);
        $contact_name = $details['first_name'] . ' ' . $details['last_name'];

        $customer_id = $this->_getCustomerIDFromContact($contact_id);
        $stmt = "SELECT
                    A.name,
                    B.support_no,
                    CONCAT(YEAR(B.startdate), '-', B.support_id) AS contract_id,
                    CONCAT(C.level, ', ', C.customer_type) AS support_level,
                    DATE_FORMAT(enddate, '%M %e, %Y') AS expiration_date
                 FROM
                    cust_entity A,
                    support B,
                    support_type C
                 WHERE
                    A.cust_no=$customer_id AND
                    A.cust_no=B.cust_no AND
                    B.support_type_no=C.support_type_no";
        if ($restrict_expiration) {
            $stmt .= " AND
                    B.status <> 'Cancelled' AND
                    NOW() <= (B.enddate + INTERVAL " . $this->_getExpirationOffset() . " DAY)";
        }
        $stmt .= "
                 ORDER BY
                    B.support_no DESC
                 LIMIT
                    0, 1";
        $res = $GLOBALS["customer_db"]->getRow($stmt, DB_FETCHMODE_ASSOC);

        list($is_per_incident, $options) = $this->getSupportOptions($res['support_no']);
        return array(
            'contact_name'    => $contact_name,
            'company_name'    => $res['name'],
            'contract_id'     => $res['contract_id'],
            'support_level'   => $res['support_level'],
            'support_options' => @implode(", ", $options),
            'expiration_date' => $res['expiration_date']
        );
    }


    /**
     * Method used to get the details associated with a customer contact.
     *
     * @access  public
     * @param   integer $contact_id The customer contact ID
     * @return  array The contact details
     */
    function getContactDetails($contact_id)
    {
        $stmt = "SELECT
                    eaddress.eaddress_code AS phone,
                    cust_entity.name2 AS first_name,
                    cust_entity.name AS last_name
                 FROM
                    cust_entity
                 LEFT JOIN
                    eaddress
                 ON
                    cust_entity.cust_no=eaddress.cust_no
                 LEFT JOIN
                    eaddress_type
                 ON
                    eaddress.eaddress_type_no=eaddress_type.eaddress_type_no AND
                    eaddress_type.descript='telephone'
                 WHERE
                    cust_entity.cust_no=$contact_id";
        $res = $GLOBALS["customer_db"]->getRow($stmt, DB_FETCHMODE_ASSOC);
        if (PEAR::isError($res)) {
            Error_Handler::logError(array($res->getMessage(), $res->getDebugInfo()), __FILE__, __LINE__);
            return '';
        } else {
            return $res;
        }
    }


    /**
     * Method used to get the customer ID associated with a given
     * customer contact ID.
     *
     * @access  private
     * @param   integer $contact_id The customer contact ID
     * @return  integer The customer ID
     */
    function _getCustomerIDFromContact($contact_id)
    {
        $stmt = "SELECT
                    up_cust_no
                 FROM
                    cust_role
                 WHERE
                    cust_no=$contact_id";
        $res = $GLOBALS["customer_db"]->getOne($stmt);
        if (PEAR::isError($res)) {
            Error_Handler::logError(array($res->getMessage(), $res->getDebugInfo()), __FILE__, __LINE__);
            return 0;
        } else {
            return $res;
        }
    }


    /**
     * Returns the list of customer IDs that are associated with the given
     * email value (wildcards welcome).
     *
     * @access  public
     * @param   string $email The email value
     * @return  array The list of customer IDs
     */
    function getCustomerIDsLikeEmail($email)
    {
        // need to restrict the customer lookup
        if (strlen($email) < 5) {
            return array();
        }
        $stmt = "SELECT
                    DISTINCT C.up_cust_no
                 FROM
                    eaddress A,
                    eaddress_type B,
                    cust_role C,
                    cust_entity D
                 WHERE
                    C.up_cust_no=D.cust_no AND
                    C.cust_no=A.cust_no AND
                    A.eaddress_type_no=B.eaddress_type_no AND
                    B.descript='email' AND
                    (
                        A.eaddress_code LIKE '%" . Misc::escapeString($email) . "%' OR
                        D.name LIKE '%" . Misc::escapeString($email) . "%'
                    )";
        $res = $GLOBALS["customer_db"]->getCol($stmt);
        if (PEAR::isError($res)) {
            Error_Handler::logError(array($res->getMessage(), $res->getDebugInfo()), __FILE__, __LINE__);
            return array();
        } else {
            return $res;
        }
    }


    /**
     * Marks the given issue ID as not redeemed.
     *
     * @access  public
     * @param   integer $issue_id The issue ID
     * @return  integer 1 if the removal worked, -1 or -2 otherwise
     */
    function unflagIncident($issue_id)
    {
        // check if the issue is not already in there
        if (!$this->isRedeemedIncident($issue_id)) {
            return -2;
        } else {
            // get the support_no from the customer associated with the given issue
            $details = $this->getDetails(Issue::getCustomerID($issue_id));
            $stmt = "DELETE FROM
                        support_issue
                     WHERE
                        support_no=" . $details['support_no'] . " AND
                        iss_id=$issue_id";
            $pres = $GLOBALS["customer_db"]->query($stmt);
            if (PEAR::isError($pres)) {
                Error_Handler::logError(array($pres->getMessage(), $pres->getDebugInfo()), __FILE__, __LINE__);
                return -1;
            } else {
                return 1;
            }
        }
    }


    /**
     * Marks the given issue ID as redeemed.
     *
     * @access  public
     * @param   integer $issue_id The issue ID
     * @return  integer 1 if the insert worked, -1 or -2 otherwise
     */
    function flagIncident($issue_id)
    {
        // check if the issue is not already in there
        if ($this->isRedeemedIncident($issue_id)) {
            return -2;
        } else {
            // get the support_no from the customer associated with the given issue
            $details = $this->getDetails(Issue::getCustomerID($issue_id));
            $stmt = "INSERT INTO
                        support_issue
                     (
                        support_no,
                        iss_id
                     ) VALUES (
                        " . $details['support_no'] . ",
                        $issue_id
                     )";
            $pres = $GLOBALS["customer_db"]->query($stmt);
            if (PEAR::isError($pres)) {
                Error_Handler::logError(array($pres->getMessage(), $pres->getDebugInfo()), __FILE__, __LINE__);
                return -1;
            } else {
                return 1;
            }
        }
    }


    /**
     * Method used to notify the customer contact that an existing issue
     * associated with him was just marked as closed.
     *
     * @access  public
     * @param   integer $issue_id The issue ID
     * @param   integer $contact_id The customer contact ID
     * @return  void
     */
    function notifyIssueClosed($issue_id, $contact_id)
    {
        list($contact_email, $void, $contact_name) = $this->getContactLoginDetails($contact_id);
        $to = Mail_API::getFormattedName($contact_name, $contact_email);
        $data = Issue::getDetails($issue_id);

        // open text template
        $tpl = new Template_API;
        $tpl->setTemplate('customer/spot/customer_closed_issue.tpl.text');
        $tpl->bulkAssign(array(
            "data"             => $data
        ));
        $text_message = $tpl->getTemplateContents();

        // send email (use PEAR's classes)
        $mail = new Mail_API;
        $mail->setTextBody($text_message);
        $setup = $mail->getSMTPSettings();
        $from = Notification::getFixedFromHeader($issue_id, $setup["from"], 'issue');
        $mail->send($from, $to, "Issue #" . $issue_id . " Closed");
    }


    /**
     * Method used to get the details of the given customer contact.
     *
     * @access  public
     * @param   integer $contact_id The customer contact ID
     * @return  array The customer details
     */
    function getContactLoginDetails($contact_id)
    {
        $stmt = "SELECT
                    eaddress.eaddress_code AS email,
                    cust_login.passwd AS password,
                    CONCAT(cust_entity.name2, ' ', cust_entity.name) AS full_name
                 FROM
                    cust_entity,
                    eaddress,
                    eaddress_type
                 LEFT JOIN
                    cust_login
                 ON
                    cust_entity.cust_no=cust_login.cust_no
                 WHERE
                    cust_entity.cust_no=eaddress.cust_no AND
                    eaddress.eaddress_type_no=eaddress_type.eaddress_type_no AND
                    eaddress_type.descript='email' AND
                    cust_entity.cust_no=$contact_id";
        $res = $GLOBALS["customer_db"]->getRow($stmt, DB_FETCHMODE_ASSOC);
        if (PEAR::isError($res)) {
            Error_Handler::logError(array($res->getMessage(), $res->getDebugInfo()), __FILE__, __LINE__);
            return -1;
        } else {
            if (empty($res)) {
                return -2;
            } else {
                return array(
                    $res['email'],
                    $res['password'],
                    $res['full_name']
                );
            }
        }
    }


    /**
     * Performs a customer lookup in Spot and returns the matches, if 
     * appropriate. It will also provide a limitation on the number of searches
     * that a given user may run against the list of customers.
     *
     * @access  public
     * @param   string $field The field that we are trying to search against
     * @param   string $value The value that we are searching for
     * @return  array The list of customers
     */
    function lookup($field, $value)
    {
        // if field is support_id and value contains a dash (2003-1678), then parse the numbers after the dash
        if (($field == 'support') && (strstr($value, '-'))) {
            $value = substr($value, strpos($value, '-') + 1);
        }

        $info = User::getNameEmail(Auth::getUserID());
        // check if this user is not trying to search too much
        $stmt = "SELECT
                    user_no
                 FROM
                    user
                 WHERE
                    email='" . $info['usr_email'] . "'";
        $user_no = $GLOBALS["customer_db"]->getOne($stmt);
        if ((PEAR::isError($user_no)) || (empty($user_no))) {
            return array();
        }

        $stmt = "SELECT
                    COUNT(*) cnt,
                    SEC_TO_TIME(UNIX_TIMESTAMP(DATE_ADD(MIN(log_when), INTERVAL 1 HOUR)) - UNIX_TIMESTAMP()) retry
                 FROM
                    cust_log
                 WHERE
                    log_type='select' AND
                    log_table='support' AND
                    log_when > DATE_SUB(NOW(), INTERVAL 1 HOUR) AND
                    user_no=$user_no";
        $res = $GLOBALS["customer_db"]->getRow($stmt, DB_FETCHMODE_ASSOC);
        if (PEAR::isError($res)) {
            Error_Handler::logError(array($res->getMessage(), $res->getDebugInfo()), __FILE__, __LINE__);
            return "";
        }
        if (empty($res)) {
            return array();
        }
        if ($res['cnt'] > 15) {
            return $res['retry'];
        }

        // now for the real thing
        $stmt = "SELECT
                    DISTINCT cust_entity.name customer_name,
                    cust_entity.cust_no customer_id,
                    CONCAT(support_type.level, ', ', support_type.customer_type) support_level,
                    CONCAT(YEAR(support.startdate), '-', support.support_id) contract_id,
                    support.enddate expiration_date,
                    IF(support.status = 'Cancelled', 'cancelled', IF(support.enddate < NOW(), 'expired', 'active')) contract_status
                 FROM
                    cust_entity,
                    support,
                    support_type,
                    cust_role";
        if ($field == 'email') {
            $stmt .= ', eaddress, cnt_support';
        }
        $stmt .= " WHERE ";
        if ($field == 'email') {
            $stmt .= "
                    eaddress_code LIKE '%" . Misc::escapeString($value) . "%' AND
                    cust_role.cust_no=eaddress.cust_no AND
                    eaddress.eaddress_type_no=1 AND
                    cnt_support.support_no=support.support_no AND
                    cnt_support.cust_no=eaddress.cust_no AND ";
        } elseif ($field == 'customer') {
            $stmt .= "cust_id='" . Misc::escapeString($value) . "' AND ";
        } else {
            // search for support_id
            $stmt .= "support_id='" . Misc::escapeString($value) . "' AND ";
        }
        $stmt .= "
                    cust_entity.cust_no=support.cust_no AND
                    cust_entity.cust_no=cust_role.up_cust_no AND
                    support.support_type_no=support_type.support_type_no
                 LIMIT
                    0, 10";
        $res = $GLOBALS["customer_db"]->getAll($stmt, DB_FETCHMODE_ASSOC);
        if (PEAR::isError($res)) {
            Error_Handler::logError(array($res->getMessage(), $res->getDebugInfo()), __FILE__, __LINE__);
            return array();
        } else {
            if (empty($res)) {
                return array();
            }
            // record the search in the log table
            $spot_values = array(
                'email'    => 'email',
                'customer' => 'cust_id',
                'support'  => 'support_id'
            );

            $stmt = "INSERT INTO
                        cust_log
                     (
                        cust_no,
                        user_no,
                        log_type,
                        log_table,
                        log_where,
                        log_what
                     ) VALUES (
                        0,
                        $user_no,
                        'select',
                        'support',
                        '" . $spot_values[$field] . "=\'" . Misc::escapeString($value) . "\'',
                        'Support QF search for " . $spot_values[$field] . " = " . Misc::escapeString($value) . ".'
                     )";
            $pres = $GLOBALS["customer_db"]->query($stmt);
            if (PEAR::isError($pres)) {
                Error_Handler::logError(array($pres->getMessage(), $pres->getDebugInfo()), __FILE__, __LINE__);
            }

            for ($i = 0; $i < count($res); $i++) {
                $res[$i]['contacts'] = $this->_getContactList($res[$i]['customer_id']);
            }
            return $res;
        }
    }


    /**
     * Returns the list of technical contacts associated with the current
     * support contract of a given customer ID.
     *
     * @access  private
     * @param   integer $customer_id The customer ID
     * @return  array The list of technical contacts
     */
    function _getContactList($customer_id)
    {
        // XXX: need to check what happens if you pass a customer that has more than one support contract on file
        $stmt = "SELECT
                    A.cust_no contact_id,
                    CONCAT(A.name, ', ', A.name2) contact_name,
                    C.eaddress_code email,
                    A.name2 contact_first_name,
                    A.name contact_last_name,
                    F.eaddress_code phone
                 FROM
                    cust_entity A,
                    cust_role B,
                    eaddress C,
                    cnt_support D,
                    support E
                 LEFT JOIN
                    eaddress F
                 ON
                    F.cust_no=A.cust_no AND
                    F.eaddress_type_no=3
                 WHERE
                    D.support_no=E.support_no AND
                    D.cust_no=B.cust_no AND
                    E.cust_no=B.up_cust_no AND
                    C.cust_no=A.cust_no AND
                    C.eaddress_type_no=1 AND
                    A.cust_type='P' AND
                    A.cust_no=B.cust_no AND
                    B.up_cust_no=$customer_id";
        $res = $GLOBALS["customer_db"]->getAll($stmt, DB_FETCHMODE_ASSOC);
        if (PEAR::isError($res)) {
            Error_Handler::logError(array($res->getMessage(), $res->getDebugInfo()), __FILE__, __LINE__);
            return "";
        } else {
            return $res;
        }
    }


    /**
     * Method used to notify the customer contact that a new issue was just
     * created and associated with his Eventum user.
     *
     * @access  public
     * @param   integer $issue_id The issue ID
     * @param   integer $contact_id The customer contact ID
     * @return  void
     */
    function notifyCustomerIssue($issue_id, $contact_id)
    {
        list($contact_email, $void, $contact_name) = $this->getContactLoginDetails($contact_id);
        $to = Mail_API::getFormattedName($contact_name, $contact_email);
        $data = Issue::getDetails($issue_id);

        // open text template
        $tpl = new Template_API;
        $tpl->setTemplate('customer/spot/customer_new_issue.tpl.text');
        $tpl->bulkAssign(array(
            "data"             => $data
        ));
        $text_message = $tpl->getTemplateContents();

        // send email (use PEAR's classes)
        $mail = new Mail_API;
        $mail->setTextBody($text_message);
        $setup = $mail->getSMTPSettings();
        $from = Notification::getFixedFromHeader($issue_id, $setup["from"], 'issue');
        $mail->send($from, $to, "New Issue #" . $issue_id);
    }


    /**
     * Method used to get the list of available support levels from Spot.
     *
     * @access  public
     * @return  array The list of available support levels
     */
    function getSupportLevelAssocList()
    {
        $stmt = "SELECT
                    support_type_no AS level_id,
                    descript AS level
                 FROM
                    support_type";
        $res = $GLOBALS["customer_db"]->getAssoc($stmt);
        if (PEAR::isError($res)) {
            Error_Handler::logError(array($res->getMessage(), $res->getDebugInfo()), __FILE__, __LINE__);
            return array();
        } else {
            return $res;
        }
    }


    /**
     * Returns the support level of the current support contract for a given 
     * customer ID.
     *
     * @access  public
     * @param   integer $customer_id The customer ID
     * @return  string The support contract level
     */
    function getSupportLevelID($customer_id)
    {
        $stmt = "SELECT
                    B.support_type_no
                 FROM
                    support A,
                    support_type B
                 WHERE
                    A.cust_no=$customer_id AND
                    NOW() <= (A.enddate + INTERVAL " . $this->_getExpirationOffset() . " DAY) AND
                    A.status <> 'Cancelled' AND
                    A.support_type_no=B.support_type_no";
        $res = $GLOBALS["customer_db"]->getOne($stmt);
        if (PEAR::isError($res)) {
            Error_Handler::logError(array($res->getMessage(), $res->getDebugInfo()), __FILE__, __LINE__);
            return 0;
        } else {
            return $res;
        }
    }


    /**
     * Returns the list of customer IDs for a given support contract level.
     *
     * @access  public
     * @param   integer $support_level_id The support level ID
     * @param   mixed $support_options An integer or array of integers indicating various options to get customers with.
     * @return  array The list of customer IDs
     */
    function getListBySupportLevel($support_level_id, $support_options)
    {
        if (!is_array($support_level_id)) {
            $support_level_id = array($support_level_id);
        }
        if (($support_options != false) && (!is_array($support_options))) {
            $support_options = array($support_options);
        } elseif ($support_options == false) {
            $support_options = array();
        }
        $stmt = "SELECT
                    A.cust_no
                 FROM
                    support A,
                    support_type B LEFT JOIN
                    support_extra C ON
                        A.support_no = C.support_no
                 WHERE
                    A.support_type_no=B.support_type_no AND
                    B.support_type_no IN (" . implode(',', $support_level_id) . ")";
        if (in_array(SPOT_CUSTOMER_OPTION_INNODB_SUPPORT, $support_options)) {
            $stmt .= " AND\n C.pl_extra_no=2";
        } elseif (in_array(SPOT_CUSTOMER_OPTION_NO_INNODB_SUPPORT, $support_options)) {
            $stmt .= " AND\n (C.pl_extra_no!=2 OR C.pl_extra_no IS NULL)";
        }
        if (in_array(CUSTOMER_EXCLUDE_EXPIRED, $support_options)) {
            $stmt .= " AND\n A.enddate > NOW()";
        }
        $res = $GLOBALS["customer_db"]->getCol($stmt);
        if (PEAR::isError($res)) {
            Error_Handler::logError(array($res->getMessage(), $res->getDebugInfo()), __FILE__, __LINE__);
            return array();
        } else {
            if (empty($res)) {
                return array();
            } else {
                return $res;
            }
        }
    }


    /**
     * Returns an array of support levels grouped together.
     * 
     */
    function getGroupedSupportLevels()
    {
        $levels = array(
            'Entry Level' => array(7, 12, 19, 20),
            'Primary'     => array(8, 13),
            'Enhanced'    => array(9, 14),
            'Premium'     => array(10)
        );
        return $levels;
    }
}
?>