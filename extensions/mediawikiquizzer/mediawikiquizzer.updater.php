<?php

/**
 * Quizzer extension for MediaWiki
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * @author Stas Fomin <stas-fomin@yandex.ru>
 * @author Vitaliy Filippov <vitalif@mail.ru>
 * @license http://www.gnu.org/copyleft/gpl.html GNU General Public License 2.0 or later
 */

/**
 * This class is responsible for parsing quiz articles and writing parsed quizzes into DB.
 * It is done using a recursive DOM parser and DOMParseUtils class.
 */
class MediawikiQuizzerUpdater
{
    static $test_field_types = array(
        'name' => 1,
        'intro' => 0,
        'mode' => 2,
        'shuffle_questions' => 3,
        'shuffle_choices' => 3,
        'limit_questions' => 4,
        'ok_percent' => 4,
        'autofilter_min_tries' => 4,
        'autofilter_success_percent' => 4
    );
    static $test_default_values = array(
        'test_name' => '',
        'test_intro' => '',
        'test_mode' => 'TEST',
        'test_shuffle_questions' => 0,
        'test_shuffle_choices' => 0,
        'test_limit_questions' => 0,
        'test_ok_percent' => 80,
        'test_autofilter_min_tries' => 0,
        'test_autofilter_success_percent' => 90,
    );
    static $test_keys;
    static $regexps;
    static $qn_keys = array('choice', 'choices', 'correct', 'corrects', 'label', 'explanation', 'comments');

    /* Parse wiki-text $text without TOC, heading numbers and EditSection links */
    static function parse($article, $text)
    {
        global $wgParser;
        if (defined('MAG_NUMBEREDHEADINGS') && ($mag = MagicWord::get(MAG_NUMBEREDHEADINGS)))
            $mag->matchAndRemove($text);
        MagicWord::get('toc')->matchAndRemove($text);
        MagicWord::get('forcetoc')->matchAndRemove($text);
        MagicWord::get('noeditsection')->matchAndRemove($text);
        $options = clone $wgParser->mOptions;
        $options->mNumberHeadings = false;
        $options->mEditSection = true;
        $options->mIsSectionPreview = true;
        /* Disable insertion of question statistics into editsection links */
        MediawikiQuizzer::$disableQuestionInfo = true;
        $html = $wgParser->parse("__NOTOC__\n$text", $article->getTitle(), $options, true, false)->getText();
        MediawikiQuizzer::$disableQuestionInfo = false;
        return $html;
    }

    /* Build regular expressions to match headings */
    static function getRegexps()
    {
        wfLoadExtensionMessages('MediawikiQuizzer');
        $test_regexp = array();
        $qn_regexp = array();
        self::$test_keys = array_keys(self::$test_field_types);
        foreach (self::$test_keys as $k)
            $test_regexp[] = '('.wfMsgNoTrans("mwquizzer-parse-test_$k").')';
        foreach (self::$qn_keys as $k)
            $qn_regexp[] = '('.wfMsgNoTrans("mwquizzer-parse-$k").')';
        $test_regexp_nq = $test_regexp;
        array_unshift($test_regexp, '('.wfMsgNoTrans('mwquizzer-parse-question').')');
        $test_regexp = str_replace('/', '\\/', implode('|', $test_regexp));
        $test_regexp_nq = '()'.str_replace('/', '\\/', implode('|', $test_regexp_nq));
        $qn_regexp = str_replace('/', '\\/', implode('|', $qn_regexp));
        self::$regexps = array($test_regexp, $test_regexp_nq, $qn_regexp);
    }

    /* Transform quiz field value according to its type */
    static function transformFieldValue($field, $value)
    {
        $t = self::$test_field_types[$field];
        if ($t > 0) /* not an HTML code */
        {
            $value = trim(strip_tags($value));
            if ($t == 2) /* mode */
                $value = strpos(strtolower($value), 'tutor') !== false ? 'TUTOR' : 'TEST';
            elseif ($t == 3) /* boolean */
            {
                $re = str_replace('/', '\\/', wfMsgNoTrans('mwquizzer-parse-true'));
                $value = preg_match("/$re/uis", $value) ? 1 : 0;
            }
            elseif ($t == 4) /* integer */
                $value = intval($value);
            /* else ($t == 1) // just a string */
        }
        else
            $value = trim($value);
        return $value;
    }

    static function textlog($s)
    {
        if (is_object($s))
            $s = DOMParseUtils::saveChildren($s);
        return trim(str_replace("\n", " ", strip_tags($s)));
    }

    /* Check last question for correctness */
    static function checkLastQuestion(&$questions, &$log)
    {
        $lq = $questions[count($questions)-1];
        $ncorrect = 0;
        $ok = false;
        if ($lq['choices'])
            foreach ($lq['choices'] as $lc)
                if ($lc['ch_correct'])
                    $ncorrect++;
        if (!$lq['choices'] || !count($lq['choices']))
            $log .= "[ERROR] No choices defined for question: ".self::textlog($lq['qn_text'])."\n";
        elseif ($ncorrect >= $lq['choices'])
            $log .= "[ERROR] All choices are correct for question: ".self::textlog($lq['qn_text'])."\n";
        elseif (!$ncorrect)
            $log .= "[ERROR] No correct choices for question: ".self::textlog($lq['qn_text'])."\n";
        else
            $ok = true;
        if (!$ok)
            array_pop($questions);
    }

    /* states: */
    const ST_OUTER = 0;     /* Outside everything */
    const ST_QUESTION = 1;  /* Inside question */
    const ST_PARAM_DD = 2;  /* Waiting for <dd> with quiz field value */
    const ST_CHOICE = 3;    /* Inside single choice section */
    const ST_CHOICES = 4;   /* Inside multiple choices section */

    /* parseQuiz() using a state machine :-) */
    static function parseQuiz2($html)
    {
        self::getRegexps();
        $log = '';
        $document = DOMParseUtils::loadDOM($html);
        /* Stack: [ [ Element, ChildIndex, AlreadyProcessed, AppendStrlen ] , [ ... ] ] */
        $stack = array(array($document->documentElement, 0, false, 0));
        $st = self::ST_OUTER;   /* State index */
        $append = NULL;         /* Array(&$str) or NULL. When array(&$str), content is appended to $str. */
        /* Variables: */
        $q = array();           /* Questions */
        $quiz = self::$test_default_values; /* Quiz field => value */
        $field = '';            /* Current parsed field */
        $correct = 0;           /* Is current choice(s) section for correct choices */
        /* Loop through all elements: */
        while ($stack)
        {
            list($p, $i, $h, $l) = $stack[count($stack)-1];
            if ($i >= $p->childNodes->length)
            {
                array_pop($stack);
                if ($append && !$h)
                {
                    /* Remove children from the end of $append[0]
                       and append element itself */
                    $append[0] = substr($append[0], 0, $l) . $document->saveXML($p);
                }
                elseif ($h && $stack)
                {
                    /* Propagate "Already processed" value */
                    $stack[count($stack)-1][2] = true;
                }
                continue;
            }
            $stack[count($stack)-1][1]++;
            $e = $p->childNodes->item($i);
            if ($e->nodeType == XML_ELEMENT_NODE)
            {
                $fall = false;
                if (preg_match('/^h(\d)$/is', $e->nodeName, $m))
                {
                    $level = $m[1];
                    $log_el = str_repeat('=', $level);
                    /* Remove editsection links */
                    $editsection = NULL;
                    if ($e->childNodes->length)
                    {
                        $span = $e->childNodes->item(0);
                        if ($span->nodeName == 'span' && $span->getAttribute('class') == 'editsection')
                        {
                            $e->removeChild($span);
                            $editsection = $document->saveXML($span);
                        }
                    }
                    $log_el = $log_el . self::textlog($e) . $log_el;
                    /* Match question/parameter section title */
                    $chk = DOMParseUtils::checkNode($e, self::$regexps[0], true);
                    if ($chk)
                    {
                        /* Question section */
                        if ($chk[1][0][0])
                        {
                            if ($q)
                                self::checkLastQuestion($q, $log);
                            /* Question section - found */
                            $log .= "[INFO] Begin question section: $log_el\n";
                            $st = self::ST_QUESTION;
                            if (preg_match('/\?([^"\'\s]*)/s', $editsection, $m))
                            {
                                /* Extract page title and section number from editsection link */
                                $es = array();
                                parse_str(htmlspecialchars_decode($m[1]), $es);
                                preg_match('/\d+/', $es['section'], $m);
                                $anch = $es['title'] . '|' . $m[0];
                            }
                            else
                                $anch = NULL;
                            $q[] = array(
                                'qn_label' => DOMParseUtils::saveChildren($chk[0], true),
                                'qn_anchor' => $anch,
                                'qn_editsection' => $editsection,
                            );
                            $append = array(&$q[count($q)-1]['qn_text']);
                        }
                        /* Quiz parameter */
                        elseif ($st == self::ST_OUTER || $st == self::ST_PARAM_DD)
                        {
                            $st = self::ST_OUTER;
                            $field = '';
                            foreach ($chk[1] as $i => $c)
                            {
                                if ($c[0])
                                {
                                    $field = self::$test_keys[$i-1];
                                    break;
                                }
                            }
                            if ($field)
                            {
                                /* Parameter - found */
                                $log .= "[INFO] Begin quiz field \"$field\" section: $log_el\n";
                                $append = array(&$quiz["test_$field"]);
                            }
                            else
                            {
                                /* This should never happen ! */
                                $line = __FILE__.':'.__LINE__;
                                $log .= "[ERROR] MYSTICAL BUG: Unknown quiz field at $line in: $log_el\n";
                            }
                        }
                        else
                        {
                            /* INFO: Parameter section inside question section / choice section */
                            $log .= "[WARN] Field section must come before questions: $log_el\n";
                        }
                    }
                    elseif ($st == self::ST_QUESTION || $st == self::ST_CHOICE || $st == self::ST_CHOICES)
                    {
                        $chk = DOMParseUtils::checkNode($e, self::$regexps[2], true);
                        if ($chk)
                        {
                            /* Question sub-section */
                            $sid = '';
                            foreach ($chk[1] as $i => $c)
                            {
                                if ($c[0])
                                {
                                    $sid = self::$qn_keys[$i];
                                    break;
                                }
                            }
                            if (!$sid)
                            {
                                /* This should never happen ! */
                                $line = __FILE__.':'.__LINE__;
                                $log .= "[ERROR] MYSTICAL BUG: Unknown question field at $line in: $log_el\n";
                            }
                            elseif ($sid == 'comments')
                            {
                                /* Question comments */
                                $log .= "[INFO] Begin question comments: $log_el\n";
                                $st = self::ST_QUESTION;
                                $append = NULL;
                            }
                            elseif ($sid == 'explanation' || $sid == 'label')
                            {
                                /* Question field */
                                $log .= "[INFO] Begin question $sid: $log_el\n";
                                $st = self::ST_QUESTION;
                                $append = array(&$q[count($q)-1]["qn_$sid"]);
                            }
                            else
                            {
                                /* Some kind of choice(s) section */
                                $correct = $sid == 'correct' || $sid == 'corrects' ? 1 : 0;
                                $lc = $correct ? 'correct choice' : 'choice';
                                if ($sid == 'correct' || $sid == 'choice')
                                {
                                    $log .= "[INFO] Begin single $lc section: $log_el\n";
                                    $q[count($q)-1]['choices'][] = array('ch_correct' => $correct);
                                    $st = self::ST_CHOICE;
                                    $append = array(&$q[count($q)-1]['choices'][count($q[count($q)-1]['choices'])-1]['ch_text']);
                                }
                                else
                                {
                                    $log .= "[INFO] Begin multiple $lc section: $log_el\n";
                                    $st = self::ST_CHOICES;
                                    $append = NULL;
                                }
                            }
                        }
                        else
                        {
                            /* INFO: unknown heading inside question */
                            $log .= "[WARN] Unparsed heading inside question: $log_el\n";
                            $fall = true;
                        }
                    }
                    else
                    {
                        /* INFO: unknown heading */
                        $log .= "[WARN] Unparsed heading outside question: $log_el\n";
                        $fall = true;
                    }
                }
                /* <dt> for a parameter */
                elseif (($st == self::ST_OUTER || $st == self::ST_PARAM_DD) && $e->nodeName == 'dt')
                {
                    $chk = DOMParseUtils::checkNode($e, self::$regexps[1], true);
                    $log_el = '; ' . trim(strip_tags(DOMParseUtils::saveChildren($e))) . ':';
                    if ($chk)
                    {
                        $st = self::ST_OUTER;
                        $field = '';
                        foreach ($chk[1] as $i => $c)
                        {
                            if ($c[0])
                            {
                                $field = self::$test_keys[$i-1];
                                break;
                            }
                        }
                        if ($field)
                        {
                            /* Parameter - found */
                            $log .= "[INFO] Begin definition list item for quiz field \"$field\": $log_el\n";
                            $st = self::ST_PARAM_DD;
                        }
                        else
                        {
                            /* This should never happen ! */
                            $line = __FILE__.':'.__LINE__;
                            $log .= "[ERROR] MYSTICAL BUG: Unknown quiz field at $line in: $log_el\n";
                        }
                    }
                    else
                    {
                        /* INFO: unknown <dt> key */
                        $log .= "[WARN] Unparsed definition list item: $log_el\n";
                        $fall = true;
                    }
                }
                elseif ($st == self::ST_PARAM_DD && $e->nodeName == 'dd')
                {
                    /* Value for $field */
                    $value = self::transformFieldValue($field, DOMParseUtils::saveChildren($e));
                    $log .= "[INFO] Quiz $field = ".self::textlog($value)."\n";
                    $quiz["test_$field"] = $value;
                    $st = self::ST_OUTER;
                    $field = '';
                }
                elseif ($st == self::ST_CHOICE && ($e->nodeName == 'ul' || $e->nodeName == 'ol') &&
                    $e->childNodes->length == 1 && !$append[0])
                {
                    /* <ul>/<ol> with single <li> inside choice */
                    $log .= "[INFO] Stripping single-item list from single-choice section";
                    $e = $e->childNodes->item(0);
                    $chk = DOMParseUtils::checkNode($e, wfMsgNoTrans('mwquizzer-parse-correct'), true);
                    if ($chk)
                    {
                        $e = $chk[0];
                        $n = count($q[count($q)-1]['choices']);
                        $q[count($q)-1]['choices'][$n-1]['ch_correct'] = 1;
                        $log .= "[INFO] Correct choice marker is present in single-item list";
                    }
                    $append[0] .= trim(DOMParseUtils::saveChildren($e));
                }
                elseif ($st == self::ST_CHOICE && $e->nodeName == 'p')
                {
                    if ($append[0])
                        $append[0] .= '<br />';
                    $append[0] .= trim(DOMParseUtils::saveChildren($e));
                }
                elseif ($st == self::ST_CHOICES && $e->nodeName == 'li')
                {
                    $chk = DOMParseUtils::checkNode($e, wfMsgNoTrans('mwquizzer-parse-correct'), true);
                    $c = $correct;
                    if ($chk)
                    {
                        $e = $chk[0];
                        $c = 1;
                    }
                    $children = DOMParseUtils::saveChildren($e);
                    $log .= "[INFO] Parsed ".($c ? "correct " : "")."choice: ".trim(strip_tags($children))."\n";
                    $q[count($q)-1]['choices'][] = array(
                        'ch_correct' => $c,
                        'ch_text' => trim($children),
                    );
                }
                else
                    $fall = true;
                if ($fall)
                {
                    /* Save position inside append-string to remove
                       children before appending the element itself */
                    $stack[] = array($e, 0, false, $append ? strlen($append[0]) : 0);
                }
                else
                    $stack[count($stack)-1][2] = true;
            }
            elseif ($append && $e->nodeType == XML_TEXT_NODE && trim($e->nodeValue))
                $append[0] .= $e->nodeValue;
        }
        if ($q)
            self::checkLastQuestion($q, $log);
        $quiz['questions'] = $q;
        $quiz['test_log'] = $log;
        return $quiz;
    }

    /* Parse $text and update data of the quiz linked to article title */
    static function updateQuiz($article, $text)
    {
        $html = self::parse($article, $text);
        $quiz = self::parseQuiz2($html);
        $quiz['test_log'] = "[INFO] Article revision: ".$article->getLatest()."\n".$quiz['test_log'];
        $quiz['test_id'] = mb_substr($article->getTitle()->getText(), 0, 32);
        if (!$quiz['questions'])
            return;
        $t2q = array();
        $qkeys = array();
        $ckeys = array();
        $questions = array();
        $choices = array();
        $hashes = array();
        foreach ($quiz['questions'] as $i => $q)
        {
            $hash = $q['qn_text'];
            foreach ($q['choices'] as $c)
                $hash .= $c['ch_text'];
            $hash = mb_strtolower(preg_replace('/\s+/s', '', $hash));
            $hash = md5($hash);
            foreach ($q['choices'] as $j => $c)
            {
                $c['ch_question_hash'] = $hash;
                $c['ch_num'] = $j+1;
                $ckeys += $c;
                $choices[] = $c;
            }
            $q['qn_hash'] = $hash;
            $hashes[] = $hash;
            unset($q['choices']);
            $qkeys += $q;
            $questions[] = $q;
            $t2q[] = array(
                'qt_test_id' => $quiz['test_id'],
                'qt_question_hash' => $hash,
                'qt_num' => $i+1,
            );
        }
        foreach ($qkeys as $k => $v)
            if (!array_key_exists($k, $questions[0]))
                $questions[0][$k] = '';
        foreach ($ckeys as $k => $v)
            if (!array_key_exists($k, $choices[0]))
                $choices[0][$k] = '';
        unset($quiz['questions']);
        $dbw = wfGetDB(DB_MASTER);
        $dbw->delete('mwq_question_test', array('qt_test_id' => $quiz['test_id']), __METHOD__);
        $dbw->delete('mwq_choice', array('ch_question_hash' => $hashes), __METHOD__);
        self::insertOrUpdate($dbw, 'mwq_test', array($quiz), __METHOD__);
        self::insertOrUpdate($dbw, 'mwq_question', $questions, __METHOD__);
        self::insertOrUpdate($dbw, 'mwq_question_test', $t2q, __METHOD__);
        self::insertOrUpdate($dbw, 'mwq_choice', $choices, __METHOD__);
    }

    /* A helper for updating many rows at once (MySQL-specific) */
    static function insertOrUpdate($dbw, $table, $rows, $fname)
    {
        global $wgDBtype;
        if ($wgDBtype != 'mysql')
            die('MediawikiQuizzer uses MySQL-specific INSERT INTO ... ON DUPLICATE KEY UPDATE by now. Fix it if you want.');
        $keys = array_keys($rows[0]);
        $sql = 'INSERT INTO ' . $dbw->tableName($table) . ' (' . implode(',', $keys) . ') VALUES ';
        foreach ($rows as &$row)
        {
            $r = array();
            foreach ($keys as $k)
                if (array_key_exists($k, $row))
                    $r[] = $row[$k];
                else
                    $r[] = '';
            $row = '(' . $dbw->makeList($r) . ')';
        }
        $sql .= implode(',', $rows);
        foreach ($keys as &$key)
            $key = "`$key`=VALUES(`$key`)";
        $sql .= ' ON DUPLICATE KEY UPDATE '.implode(',', $keys);
        return $dbw->query($sql, $fname);
    }
}

