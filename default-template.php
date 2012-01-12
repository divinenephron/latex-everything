\documentclass[12pt]{article}
\usepackage{fullpage}
\usepackage{graphicx}
\usepackage{url}
\setlength{\parskip}{1ex}
\setlength{\parindent}{0ex}

<?php the_post() ?>

\date{<?php the_date() ?>}
\title{<?php the_title() ?>}
\author{<?php the_author() ?>}

\begin{document}

\maketitle

<?php a2l_latex( get_the_content() ) ?>

\end{document}
