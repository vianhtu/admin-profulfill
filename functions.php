<?php
function renderMenu($currentMenu) {
	$menuItems = [
		'Dashboards' => [
			'icon' => 'tabler-smart-home',
			'sub' => [
				'analytics' => 'Analytics'
			]
		],
		'eCommerce' => [
			'icon' => 'tabler-shopping-cart',
			'sub' => [
				'products' => 'Products',
				'stores' => 'Stores',
				'types' => 'Types',
				'sites' => 'Sites',
				'tags' => 'Tags',
				'keywords' => 'Keywords',
				'accounts' => 'Accounts',
				'export-settings' => 'Export Settings'
			]
		]
	];

	foreach ($menuItems as $mainLabel => $mainData) {
		$isOpen = false;
		$subMenuHtml = '';

		foreach ($mainData['sub'] as $key => $value) {
			$label = is_array($value) ? $value['label'] : $value;
			$target = is_array($value) && isset($value['target']) ? 'target="_blank"' : '';
			$activeClass = ($currentMenu === $key) ? 'active' : '';
			if ($activeClass) $isOpen = true;

			$subMenuHtml .= <<<HTML
                <li class="menu-item {$activeClass}">
                    <a href="index.php?menu={$key}" class="menu-link" {$target}>
                        <div data-i18n="{$label}">{$label}</div>
                    </a>
                </li>
            HTML;
		}

		$openClass = $isOpen ? 'active open' : '';
		$icon = $mainData['icon'];

		echo <<<HTML
            <li class="menu-item {$openClass}">
                <a href="javascript:void(0);" class="menu-link menu-toggle">
                    <i class="menu-icon icon-base ti {$icon}"></i>
                    <div data-i18n="{$mainLabel}">{$mainLabel}</div>
                </a>
                <ul class="menu-sub">
                    {$subMenuHtml}
                </ul>
            </li>
        HTML;
	}
}