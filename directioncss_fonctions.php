<?php

if (!defined('_ECRIRE_INC_VERSION')) return;


/**
 * Inverse le code CSS (left <--> right) d'une feuille de style CSS
 *
 * Récupère le chemin d'une CSS existante et :
 *
 * 1. regarde si une CSS inversée droite-gauche existe dans le meme répertoire
 * 2. sinon la crée (ou la recrée) dans `_DIR_VAR/cache_css/`
 *
 * Si on lui donne à manger une feuille nommée `*_rtl.css` il va faire l'inverse.
 *
 * @filtre
 * @example
 *     ```
 *     [<link rel="stylesheet" href="(#CHEMIN{css/perso.css}|direction_css)" type="text/css" />]
 *     ```
 * @param string $css
 *     Chemin vers le fichier CSS
 * @param string $voulue
 *     Permet de forcer le sens voulu (en indiquant `ltr`, `rtl` ou un
 *     code de langue). En absence, prend le sens de la langue en cours.
 *
 * @return string
 *     Chemin du fichier CSS inversé
 **/
function filtre_direction_css_dist($css, $voulue = '') {
	if (!preg_match(',(_rtl)?\.css$,i', $css, $r)) {
		return $css;
	}

	// si on a precise le sens voulu en argument, le prendre en compte
	if ($voulue = strtolower($voulue)) {
		if ($voulue != 'rtl' and $voulue != 'ltr') {
			$voulue = lang_dir($voulue);
		}
	} else {
		$voulue = lang_dir();
	}

	$r = count($r) > 1;
	$right = $r ? 'left' : 'right'; // 'right' de la css lue en entree
	$dir = $r ? 'rtl' : 'ltr';
	$ndir = $r ? 'ltr' : 'rtl';

	if ($voulue == $dir) {
		return $css;
	}

	if (
		// url absolue
		preg_match(",^https?:,i", $css)
		// ou qui contient un ?
		or (($p = strpos($css, '?')) !== false)
	) {
		$distant = true;
		$cssf = parse_url($css);
		$cssf = $cssf['path'] . ($cssf['query'] ? "?" . $cssf['query'] : "");
		$cssf = preg_replace(',[?:&=],', "_", $cssf);
	} else {
		$distant = false;
		$cssf = $css;
		// 1. regarder d'abord si un fichier avec la bonne direction n'est pas aussi
		//propose (rien a faire dans ce cas)
		$f = preg_replace(',(_rtl)?\.css$,i', '_' . $ndir . '.css', $css);
		if (@file_exists($f)) {
			return $f;
		}
	}

	// 2.
	$dir_var = sous_repertoire(_DIR_VAR, 'cache-css');
	$f = $dir_var
		. preg_replace(',.*/(.*?)(_rtl)?\.css,', '\1', $cssf)
		. '.' . substr(md5($cssf), 0, 4) . '_' . $ndir . '.css';

	// la css peut etre distante (url absolue !)
	if ($distant) {
		include_spip('inc/distant');
		$res = recuperer_url($css);
		if (!$res or !$contenu = $res['page']) {
			return $css;
		}
	} else {
		if ((@filemtime($f) > @filemtime($css))
			and (_VAR_MODE != 'recalcul')
		) {
			return $f;
		}
		if (!lire_fichier($css, $contenu)) {
			return $css;
		}
	}


	// Inverser la direction gauche-droite en utilisant CSSTidy qui gere aussi les shorthands
	include_spip("lib/csstidy/class.csstidy");
	$parser = new csstidy();
	$parser->set_cfg('optimise_shorthands', 0);
	$parser->set_cfg('reverse_left_and_right', true);
	$parser->parse($contenu);

	$contenu = $parser->print->plain();


	// reperer les @import auxquels il faut propager le direction_css
	preg_match_all(",\@import\s*url\s*\(\s*['\"]?([^'\"/][^:]*)['\"]?\s*\),Uims", $contenu, $regs);
	$src = array();
	$src_direction_css = array();
	$src_faux_abs = array();
	$d = dirname($css);
	foreach ($regs[1] as $k => $import_css) {
		$css_direction = direction_css("$d/$import_css", $voulue);
		// si la css_direction est dans le meme path que la css d'origine, on tronque le path, elle sera passee en absolue
		if (substr($css_direction, 0, strlen($d) + 1) == "$d/") {
			$css_direction = substr($css_direction, strlen($d) + 1);
		} // si la css_direction commence par $dir_var on la fait passer pour une absolue
		elseif (substr($css_direction, 0, strlen($dir_var)) == $dir_var) {
			$css_direction = substr($css_direction, strlen($dir_var));
			$src_faux_abs["/@@@@@@/" . $css_direction] = $css_direction;
			$css_direction = "/@@@@@@/" . $css_direction;
		}
		$src[] = $regs[0][$k];
		$src_direction_css[] = str_replace($import_css, $css_direction, $regs[0][$k]);
	}
	$contenu = str_replace($src, $src_direction_css, $contenu);

	$contenu = urls_absolues_css($contenu, $css);

	// virer les fausses url absolues que l'on a mis dans les import
	if (count($src_faux_abs)) {
		$contenu = str_replace(array_keys($src_faux_abs), $src_faux_abs, $contenu);
	}

	if (!ecrire_fichier($f, $contenu)) {
		return $css;
	}

	return $f;
}
