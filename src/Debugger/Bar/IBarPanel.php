<?php

/**
 * This file is part of the Tracy (https://tracy.nette.org)
 * Copyright (c) 2004 David Grudl (https://davidgrudl.com)
 */

declare(strict_types=1);

namespace Northrook\Debugger\Bar;

/**
 * Custom output for Debugger.
 */
interface IBarPanel
{
    /**
     * Renders HTML code for custom tab.
     *
     * @return string
     */
    public function getTab() : string;

    /**
     * Renders HTML code for custom panel.
     *
     * @return string
     */
    public function getPanel() : string;
}
