<?php
/* HTML-to-Latex
 * -------------
 * Use the functions html_to_latex() and get_html_to_latex() to convert HTML strings to Latex.
 * This has been mostly based on html2latex.pm by Peter Thatcher (http://html2latex.sourceforge.net/).
 *
 * Filters
 * -------
 * a2l_tags             The tags array used to determine which handler function alter the
 *                      Latex output. It take the form.
 *                      Array( '<tag_name>' => Array ( 'handler' => <callback>,
 *                                                     'tex' => <data for callback> ),
 *                             '<tag_name>' => [...]
 *                             );
 *                      Tags that aren't in the tags array are ignored, while their children
 *                      and the text inside them are printed.
 *                      To create your own handler, just add, or change an entry in this array
 *                      so that it points to your callback.
 *                      Arguments:
 *                      $tags (The default array of tags)
 *
 * a2l_<name>_element   Filters the latex generated for the specifed element just before
 *                      it is added to the output for get_html_to_latex().
 *                      Arguments:
 *                      $latex (The Latex generated from the element)
 *                      $element (The DOMElement object the Latex was generated from)
 *
 * a2l_text             Filters the text between HTML nodes just before it is added to the
 *                      output for get_html_to_latex(). I use it to convert quote to latex
 *                      style (``...'') and to detect urls.
 *                      Arguments:
 *                      $text (The text between HTML nodes)
 *                      $element (The DOMText object the text is from)
 */

define( 'H2L_DEBUG', false );

class A2l_Html_To_Latex {
    var $tags;

    /* Creates the html_to_latex object with an initial tags array.
     * Tags is an associative array that links html tags with their handler
     * function (handler functions have names of the form "_[name]_handler")
     * and tex command. Tags not in the array will be ignored, but have
     * their children parsed.
     */
    function __construct () {
        $this->tags = Array(
                'b'          => Array ( 'handler' => 'command',     'tex' => 'texbf'            ),
                'br'         => Array ( 'handler' => 'single',      'tex' => '\\'               ),
                'blockquote' => Array ( 'handler' => 'environment', 'tex' => 'quote'            ),
                'center'     => Array ( 'handler' => 'environment', 'tex' => 'center'           ),
                'code'       => Array ( 'handler' => 'environment', 'tex' => 'verbatim'         ),
                'dd'         => Array ( 'handler' => 'other',       'tex' => Array( '',         
                        "\n" )      ),
                'dl'         => Array ( 'handler' => 'environment', 'tex' => 'description'      ),
                'dt'         => Array ( 'handler' => 'other',       'tex' => Array( '\item',
                        ']' )       ),
                'em'         => Array ( 'handler' => 'command',     'tex' => 'emph'             ),
                'h1'         => Array ( 'handler' => 'command',     'tex' => 'section*'         ),
                'h2'         => Array ( 'handler' => 'command',     'tex' => 'subsection*'      ),
                'h3'         => Array ( 'handler' => 'command',     'tex' => 'subsubsection*'   ),
                'h4'         => Array ( 'handler' => 'command',     'tex' => 'textbf'           ),
                'h5'         => Array ( 'handler' => 'command',     'tex' => 'textbf'           ),
                'h6'         => Array ( 'handler' => 'command',     'tex' => 'textbf'           ),
                'hr'         => Array ( 'handler' => 'single',      'tex' => '\hline'           ),
                'i'          => Array ( 'handler' => 'command',     'tex' => 'emph'             ),
                'img'        => Array ( 'handler' => 'image',       'tex' => 'includegraphics'  ),
                'li'         => Array ( 'handler' => 'single',      'tex' => '\item'            ),
                'ol'         => Array ( 'handler' => 'environment', 'tex' => 'enumerate'        ),
                'p'          => Array ( 'handler' => 'single',      'tex' => "\n\n"             ),
                'pre'        => Array ( 'handler' => 'environment', 'tex' => 'verbatim'         ),
                'script'     => Array ( 'handler' => 'ignore',      'tex' => ''                 ),
                'strong'     => Array ( 'handler' => 'command',     'tex' => 'textbf'           ),
                'table'      => Array ( 'handler' => 'table',       'tex' => 'table'            ),
                'td'         => Array ( 'handler' => 'table',       'tex' => 'tr'               ),
                'title'      => Array ( 'handler' => 'command',     'tex' => 'title'            ),
                'tr'         => Array ( 'handler' => 'table',       'tex' => 'td'               ),
                'ul'         => Array ( 'handler' => 'environment', 'tex' => 'itemize'          ),
                );
        // Convert handlers to the form [$this, _<type>_handler].
        foreach ( $this->tags as $tag => $value ) {
            $handler = "_{$this->tags[$tag]['handler']}_handler";
            $this->tags[$tag]['handler'] = Array( $this, $handler );
        }
        $this->tags = apply_filters( 'a2l_tags', $this->tags );
    }

    /* Function to convert an html string into Latex. Used by the get_html_to_latex()
     * function.
     */
    function html_to_latex ( $html_string ) {

        $doc = new DOMDocument();
        libxml_use_internal_errors( TRUE );
        if ( ! $doc->loadHTML( $html_string ) ) {
            return "Error: DOMDocument couldn't parse html";
        }

        $latex = $this->_texify( $doc );

        return $latex;
    }

    /* Iterates over the children of the node. Elements have their handlers called,
     * (which usually print something then call texify again) while text is printed.
     */
    function _texify ( $parent_element ) {
        $output = '';

        $elements = $parent_element->childNodes;
        foreach ( $elements as $element ) {
            if ( $element->nodeType == XML_ELEMENT_NODE ) {
                // This is an html element
                if ( array_key_exists( $element->tagName, $this->tags ) ) {
                    // If the tag has a handler, send it to the handler.
                    $tag = $this->tags[$element->tagName];
                    $handler = $tag['handler'];
                    if (H2L_DEBUG)
                        error_log( "Sending {$element->tagName} tag to {$handler[1]} handler. \"{$element->textContent}\"" );
                    if ( is_callable( $handler ) ) {
                        $output .= apply_filters( "a2l_{$element->tagName}_element",
                                call_user_func( $handler, $element, $tag['tex'] ),
                                $element );
                    } else {
                        $output .= $this->_texify( $element );
                    }
                } else {
                    // Otherwise, ignore the element and texify its contents.
                    $output .= $this->_texify( $element );
                }
            } else if ( $element->nodeType == XML_TEXT_NODE ) {
                if ( H2L_DEBUG )
                    error_log( "Have text node: {$element->wholeText}" );
                $output .= get_latex_text( $element->wholeText );
            } else if ( $element->nodeType == XML_DOCUMENT_NODE ) {
                $output .= $this->_texify( $element );

            }
        }
        return $output;
    }

    /* HTML:    <foo> Bar </foo>
     * Latex:   \command{Bar}
     */
    function _command_handler( $element, $command ) {
        return "\\{$command}{" . $this->_texify( $element ) . "}\n";
    }

    /* HTML:    <foo> Bar </foo>
     * Latex:   tex1 Bar tex2
     */
    function _other_handler( $element, $tex ) {
        return $tex[0] . $this->_texify( $element ) . $tex[1];
    }

    /* HTML:    <foo> Bar </foo>
     * Latex:   \begin{tex} Bar \end{tex}
     */
    function _environment_handler( $element, $environment ) {
        return "\\begin{{$environment}}\n" . $this->_texify( $element ) . "\n\\end{{$environment}}\n";

    }

    /* HTML:    <foo> [Bar </foo>]
     * Latex:   \tex [Bar]
     */
    function _single_handler( $element, $tex ) {
        return "{$tex} " . $this->_texify( $element ) . "\n";

    }

    /* Does nothing with the element and its children.
     */
    function _ingore_handler( $element, $tex ) {
        return '';
    }

    function _table_handler( $element, $tex ) {
        $output = '';
        if ( $tex == 'table' ) {
            $output = $this->_create_latex_table( $element );
        } else {
            // It's a tr or td. Create_latex_table does all of the work,
            // so we just output the texified content.
            $output = $this->_texify( $element );
        }
        return $output;
    }

    /* Creates a Latex table from a <table> element. */
    function _create_latex_table( $table ) {
        $output = '';

        // Find the size of the table
        $rows = $table->getElementsByTagName('tr');
        $row_count = $rows->length;

        $column_count = 0;
        foreach( $rows as $row ) {
            $columns = $this->_get_tr_columns( $row );
            if( $columns->length > $column_count )
                $column_count = $columns->length;
        }

        // Create column alignments for every column based on the first row
        $column_alignments = '';
        if( $row = $rows->item(0) ) {
            $columns = $this->_get_tr_columns( $row );
            for( $c = 0; $c < $column_count; ++$c ) {
                $column = $columns->item( $c );
                if( $column ) {
                    $align = $column->getAttribute( 'align' );
                    if( $align == 'right' )
                        $align = 'r';
                    else if( $align == 'center' )
                        $align = 'c';
                    else
                        $align = 'l';
                } else { // No colums at this index on the first row, so repeat alignments
                    $align = substr( $column_alignments, -1 ) or $align = 'l';
                }
                $column_alignments .= $align;
            }
        }

        $output .= "\n\n\\begin{tabular}{{$column_alignments}}\n";
        $output .= "\\hline\n";

        for( $r = 0; $r < $row_count; ++$r ) {
            $row = $rows->item( $r );
            $columns = $this->_get_tr_columns( $row );
            for ( $c = 0; $c < $column_count; ++$c ) {
                $column = $columns->item( $c );
                // Write the contents
                if ( $column )
                    $output .= "{$this->_texify( $column )} ";
                // Add punctuation between columns when not the last one
                if( $c < $column_count - 1 )
                    $output .= "& ";
            }
            // Add punctuation at the end of the row
            $output .= "\\\\\n";
            // Add lines under header rows
            if ( $column && $column->tagName == 'th' )
                $output .= "\\hline\n";
        }

        $output .= "\\hline\n";
        $output .= "\\end{tabular}\n\n";

        return $output;
    }

    /* Returns the column from a <tr> element, regardless of whether
     * they're <td> or <th> elements
     */
    function _get_tr_columns( $row ) {
        $columns = $row->getElementsByTagName('th');
        if ($columns->length == 0)
            $columns = $row->getElementsByTagName('td');
        return $columns;
    }

    /* HTML:    <img src="bar.png">
     * Latex:   \includegraphic{bar.png}
     */
    function _image_handler( $element, $tex ) {
        $source = $this->_get_local_image( $element->getAttribute( 'src' ) );
        $alt = $element->getAttribute( 'alt' );

        if ( $source ) {
            return "\\{$tex}{{$source}}";
        } else {
            // Image couldn't be found
            return $alt;
        }
    }

    /* Translates a url pointing to a local file into the local file's path.
     * If the file doesn't exist on the server, returns an empty string.
     */
    function _get_local_image( $src ) {
        $path = str_replace( site_url(), ABSPATH, $src );
        if( file_exists( $path ) )
            return $path;
        else
            return '';

    }
}

// API functions.
function h2l ( $html ) {
    html_to_latex( $html );
}
function html_to_latex ( $html ) {
    echo get_html_to_latex( $html );
}
function get_html_to_latex ( $html ) {
    global $a2l_html_to_latex;
    if ( empty ( $a2l_html_to_latex ) )
        $a2l_html_to_latex = new A2l_Html_To_Latex();
    return $a2l_html_to_latex->html_to_latex( $html );
}

function lt( $text ) {
    latex_text( $text );
}
function latex_text( $text ) {
    echo get_latex_text( $text );
}
function get_latex_text ( $text ) {
    return apply_filters( 'a2l_latex_text', $text );
}

// Filters.
// Run on html text nodes before output
function le_quote_expansion_filter ( $text ) {
    $text = preg_replace( '/([^\s\[\{\)~])"/', "$1''", $text );
    $text = preg_replace( '/"/', '``', $text );
    return $text;
}

// Run on html text nodes before output
function le_urlify_filter ( $text ) {
    // Wraps urls in \url{}
    // Lovingly stolen from http://daringfireball.net/2010/07/improved_regex_for_matching_urls
    $pattern = '/(?i)\b((?:[a-z][\w-]+:(?:\/{1,3}|[a-z0-9%])|www\d{0,3}[.]|[a-z0-9.\-]+[.][a-z]{2,4}\/)(?:[^\s()<>]+|\(([^\s()<>]+|(\([^\s()<>]+\)))*\))+(?:\(([^\s()<>]+|(\([^\s()<>]+\)))*\)|[^\s`!()\[\]{};:\'".,<>?«»“”‘’]))/';
    return preg_replace( $pattern,
            '\url{$0}',
            $text );
}

// Replace special characters with the appropriate Latex macros.
function le_special_char_filter ( $text ) {
    $specials =     array(  '$',  '<',   '>',   '&',  '%',  '#',  '{',  '}',  '_' );
    $replacements = array( '\$', '$<$', '$>$', '\&', '\%', '\#', '\{', '\}', '\_' );
    $text = str_replace( '\\', '$\backslash$', $text );
    $text = str_replace( $specials, $replacements, $text );
    return $text;
}

// Run on <img> and <table> nodes before output
function le_float_filter ( $latex ) {
    return "\\begin{figure}[htbp]\n{$latex}\n\\end{figure}\n";
}

// Register filters
add_filter('a2l_img_element', 'le_float_filter' );
add_filter('a2l_table_element', 'le_float_filter' );
add_filter('a2l_latex_text', 'le_urlify_filter' );
add_filter('a2l_latex_text', 'le_quote_expansion_filter' );
add_filter('a2l_latex_text', 'le_special_char_filter' );
