\documentclass[12pt]{article}
\usepackage{fullpage}
\usepackage{graphicx}
\usepackage{url}
\setlength{\parskip}{1ex}
\setlength{\parindent}{0ex}

<?php the_post() ?>

\date{<?php h2l( get_the_date() ) ?>}
\title{<?php h2l( get_the_title() ) ?>}
\author{<?php h2l( get_the_author() ) ?>}

\begin{document}

\maketitle

<?php h2l( apply_filters( 'the_content', get_the_content() ) ) ?>

\end{document}
