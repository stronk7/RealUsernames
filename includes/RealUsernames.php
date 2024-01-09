<?php

namespace MediaWiki\Extension;

use MediaWiki\Linker\Hook\HtmlPageLinkRendererBeginHook;

use ConfigFactory;
use Html;
use HtmlArmor;
use Linker;
use RequestContext;
use Title;
use TitleValue;
use User;

/**
 * Show user real name (almost) everywhere within a wiki site.
 *
 * @copyright 2013 onwards Eloy Lafuente (stronk7)
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause
 */
class RealUsernames implements HtmlPageLinkRendererBeginHook {

    /**
     * Let's cache page (articleid) existence for every title, namespace pair.
     */
    protected static $articleids = array();

    /**
     * Let's cache the already found username => realusername pairs.
     */
    protected static $realusernames = array();

    /**
     * Replace some text by intercepting the parser:
     *   - userpage-userdoesnotexist: When editing user page. Make it also check for real username.
     *   - ...
     */
    public static function hookParser(&$parser, &$text, &$strip_state) {
        // Get the current user.
        $user = RequestContext::getMain()->getUser();

        // Get the configuration.
        $config = ConfigFactory::getDefaultInstance()->makeConfig('RealUsernames');
        $linkText = $config->get('RealUsernames_linktext');
        $linkRef = $config->get('RealUsernames_linkref');
        $appendUsername = $config->get('RealUsernames_append_username');

        // Get the current user.
        $user = RequestContext::getMain()->getUser();

        // Nothing to do if text and ref replacement are not enabled.
        if ($linkText !== true && $linkRef !== true) {
            return true;
        }

        // userpage-userdoesnotexist
        if (preg_match('!mw-userpage-userdoesnotexist error!', $text) !== 0) {
            wfDebugLog('RealUsernames', __METHOD__ . ": Text intercepted " . $text);
            // Get the title, to check if this is a user page being edited/create.
            $title = $parser->getTitle();
            if (in_array($title->getNamespace(), array(NS_USER, NS_USER_TALK))) {
                // This is a real username, in user or talk page, verify it exists in DB.
                $dbr = wfGetDB( DB_REPLICA );
                $s = $dbr->selectRow( 'user', array( 'user_id' ), array( 'user_real_name' => $title->getText() ), __METHOD__ );
                // User exists, don't output the error
                if ( $s !== false ) {
                    $text = '';
                    wfDebugLog('RealUsernames', __METHOD__ . ": User exists by real username. Cleaning error message");
                } else {
                    wfDebugLog('RealUsernames', __METHOD__ . ": User does not exist by real username. Keeping error message");
                }
            }
        }

        // Note that signatures cannot be handled here because they are processed on save
        // (pstPass2) and not by the parser itself, so they arrive here already converted. It
        // would be possible to add an ArticlePrepareTextForEdit but instead we have applied
        // a safer and quicker 1-line hack to getUserSig().

        // Others go here...

        return true;
    }

    /**
     * Replace the texts and refs in the personal urls (top-right)
     */
    public static function hookPersonalUrls(array &$personal_urls, Title $title) {
        // Get the current user.
        $user = RequestContext::getMain()->getUser();

        // Get the configuration.
        $config = ConfigFactory::getDefaultInstance()->makeConfig('RealUsernames');
        $linkText = $config->get('RealUsernames_linktext');
        $linkRef = $config->get('RealUsernames_linkref');
        $appendUsername = $config->get('RealUsernames_append_username');

        // Nothing to do if text and ref replacement are not enabled.
        if ($linkText !== true && $linkRef !== true) {
            return true;
        }

        $username = $user->getName();
        wfDebugLog('RealUsernames', __METHOD__ . ": personal urls received for " . $username);

        // Get the real username for the username
        $realusername = self::get_realusername_from_username($username);
        // Default to username if not realusername is found.
        if ($realusername === '') {
            $realusername = $username;
        } else {
            wfDebugLog('RealUsernames', __METHOD__ . ": personal urls change ". $username . " to " . $realusername);
        }

        // Let's apply real usernames to the texts.
        if ($linkText === true) {
            // To the "userpage" text
            if (isset($personal_urls['userpage'])) {
                if ($personal_urls['userpage']['text'] === $username) {
                    $text = $realusername;
                    // With $appendUsername enabled, users with "block" permissions
                    // see the username together with the real username.
                    if ($appendUsername === true && $user->isAllowed('block')) {
                        $text = $text . ' (' . $username . ')';
                    }
                    $personal_urls['userpage']['text'] = $text;
                }
            }
            // Nothing to change in the "mytalk" text
        }

        // Let's apply real usernames to the hrefs.
        if ($linkRef === true) {
            // To the "userpage" href
            if (isset($personal_urls['userpage'])) {
                $title = Title::newFromText($realusername, NS_USER);
                if (!is_object($title)) {
                    throw new MWException(__METHOD__ . " given invalid real username $realusername");
                }
                $personal_urls['userpage']['href'] = $title->getLocalURL();
                $personal_urls['userpage']['class'] = $title->getArticleID() != 0 ? false : 'new';
            }
            // To the "mytalk" href
            if (isset($personal_urls['mytalk'])) {
                $title = Title::newFromText($realusername, NS_USER_TALK);
                if (!is_object($title)) {
                    throw new MWException(__METHOD__ . " given invalid real username $realusername");
                }
                $personal_urls['mytalk']['href'] = $title->getLocalURL();
                $personal_urls['mytalk']['class'] = $title->getArticleID() != 0 ? false : 'new';
            }
        }

        return true;
    }

    /**
     * Replace the texts and refs to any NS_USER and NS_USER_TALK page to the realname alternative.
     */
    public function onHtmlPageLinkRendererBegin($linkRenderer, $target, &$text, &$extraAttribs, &$query, &$ret) {
        // Get the current user.
        $user = RequestContext::getMain()->getUser();

        // Get the configuration.
        $config = ConfigFactory::getDefaultInstance()->makeConfig('RealUsernames');
        $linkText = $config->get('RealUsernames_linktext');
        $linkRef = $config->get('RealUsernames_linkref');
        $appendUsername = $config->get('RealUsernames_append_username');

        // Nothing to do if text and ref replacement are not enabled.
        if (!$linkText && !$linkRef) {
            return true;
        }

        // Nothing to do if links are not to user and talk namespaces.
        if (!in_array($target->getNamespace(), array(NS_USER, NS_USER_TALK))) {
            return true;
        }

        // Default values, to start with.
        $username = $convertedtext = $target->getText();

        // If the namespace is user talk, then the text is always "talk".
        if ($target->getNamespace() === NS_USER_TALK) {
            $convertedtext = wfMessage('talkpagelinktext')->text();
        }

        // Get the real username for the username
        $realusername = self::get_realusername_from_username($username);
        // Default to username if not realusername is found.
        if ($realusername === '') {
            $realusername = $username;
        } else {
            $ns = $target->getNsText();
            wfDebugLog('RealUsernames', __METHOD__ . ": change '$ns:$username' to '$ns:$realusername'");
        }

        // Let's apply real usernames to the texts ($text). Only for use namespace.
        if ($linkText && $target->getNamespace() === NS_USER) {
            $convertedtext = $realusername;
            // With $appendUsername enabled, users with "block" permissions
            // see the username together with the real username.
            if ($appendUsername && $user->isAllowed('block')) {
                // Only if real username and username are different.
                if ($username !== $realusername) {
                    $convertedtext = $convertedtext . ' (' . $username . ')';
                }
            }
            // Finally, enclose it in a <bdi> tag to avoid LTR/RTL issues.
            $convertedtext = new HtmlArmor("<bdi>$convertedtext</bdi>");
            // Arrived here, if we only want to change the text, let's do it and done.
            if (!$linkRef) {
                $text = $convertedtext;
                wfDebugLog('RealUsernames', __METHOD__ . ": link text change to " . $convertedtext);
                return true; // Use $text.
            }
        }

        // We also want to change the ref, we need to do that manipulating the $ret (anchor html tag) directly.
        // Let's apply real usernames to the hrefs.
        if ($linkRef) {
            // Create a link to the real username page.
            // Calculate which the new real target is going to be.
            $realTarget = Title::newFromText($realusername, $target->getNamespace());
            $convertedref = $realTarget->getLocalURL();
            $classes = isset($extraAttribs['class']) ? $extraAttribs['class'] : '';
            // Get and cache articleID (user and talk page ids) to render a good or wrong link.
            $id = self::get_article_id($realTarget);

            // Add the new class and edit URL if the page does not exist.
            if (!$realTarget->isKnown()) {
                $classes = 'new' . (!empty($classes['class']) ? ' ' . $classes['class'] : '');
                $convertedref = $realTarget->getLocalURL(['action' => 'edit', 'redlink' => '1']);
            }

            // Finally, create the new anchor tag.
            $ret = Html::rawElement(
                'a',
                [
                    'href' => $convertedref,
                    'class' => $classes,
                ],
                HtmlArmor::getHtml($convertedtext)
            );
            wfDebugLog('RealUsernames', __METHOD__ . ": link text and href change to " . $convertedref);
        }
        return false; // Use $ret.
    }

    /**
     * Return and cache articleids for a given title object.
     *
     * @return the corresponding articleID or null
     */
    protected static function get_article_id($title) {

        $cachekey = $title->getDBkey() . '$#$' . $title->getNamespace();

        // If the $title is not in the cache, let's look for it.
        if (!isset(self::$articleids[$cachekey])) {
            wfDebugLog('RealUsernames', __METHOD__ . ": not cached articleid: " . $cachekey);
            self::$articleids[$cachekey] = $title->getArticleID($title::GAID_FOR_UPDATE);
        } else {
            wfDebugLog('RealUsernames', __METHOD__ . ": cached articleid: " . self::$articleids[$cachekey] . " for " . $cachekey);
        }
        wfDebugLog('RealUsernames', __METHOD__ . ": found articleid: " . self::$articleids[$cachekey] . " for " . $cachekey);
        return self::$articleids[$cachekey];
    }

    /**
     * Return and cache username => realusername pairs.
     *
     * @return the corresponding real username or empty string.
     */
    protected static function get_realusername_from_username($username) {

        // If the user is not in the cache, let's look for it
        if (!isset(self::$realusernames[$username])) {
            wfDebugLog('RealUsernames', __METHOD__ . ": not cached user: " . $username);

            // Verify the user is valid
            $user = User::newFromName($username, true);
            if (!is_object($user)) {
                wfDebugLog('RealUsernames', __METHOD__ . ": problem, invalid user: " . $username);
                self::$realusernames[$username] = '';
            } else {
                self::$realusernames[$username] = $user->getRealName();
            }
        } else {
            wfDebugLog('RealUsernames', __METHOD__ . ": cached user: " . $username);
        }

        if (self::$realusernames[$username] === '') {
            wfDebugLog('RealUsernames', __METHOD__ . ": no realname found for " . $username);
        } else {
            wfDebugLog('RealUsernames', __METHOD__ . ": found realname " . self::$realusernames[$username] . " for " . $username);
        }

        // Arrived here, we have a realusername to apply.
        return self::$realusernames[$username];
    }
}
