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

<?php html_to_latex( apply_filters('the_content',get_the_content( $more_link_text, $stripteaser, $more_file ))) ?>

\end{document}
