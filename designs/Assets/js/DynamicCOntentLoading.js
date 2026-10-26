$(document).ready(() => {
  const $contentArea = $("#main");
  const $links = $(".nav-link");

  if (!$contentArea.length) {
    console.error('Error: Element with id="content" not found in index.html');
    $("body").html(
      '<p>Error: Content area not found. Please check index.html for id="content".</p>'
    );
    return;
  }

  let currentCssLink = null;

  function loadPage($link) {
    const pageUrl = $link.attr("href");
    const cssFile = $link.data("css");
    const jsFile = $link.data("js");
    const pageKey = $link.data("page");

    if (!pageUrl || !pageKey) {
      console.error("Missing href or data-page on link:", $link);
      return;
    }

    $contentArea.html("<p>Loading...</p>");

    if (currentCssLink) {
      currentCssLink.remove();
      currentCssLink = null;
    }

    if (cssFile) {
      const $cssLink = $("<link>", {
        rel: "stylesheet",
        type: "text/css",
        id: "dynamic-css",
        href: cssFile,
      });
      $cssLink.on("error", () =>
        console.error(`Failed to load CSS: ${cssFile}`)
      );
      $("head").append($cssLink);
      currentCssLink = $cssLink;
    }

    $.ajax({
      url: pageUrl,
      method: "GET",
      dataType: "html",
      success: (data) => {
        let cleanedData = data.replace(
          /<!-- Code injected by live-server -->[\s\S]*?<!-- \]\]>/g,
          ""
        );
        // Fix image paths in HTML content before injection
        cleanedData = cleanedData.replace(
          /\.\.\/Assets\/img\//g,
          "/SmartClass/Assets/img/"
        );
        try {
          $contentArea.html(cleanedData);
          // Add error handling for images after injection
          $contentArea.find("img").on("error", function () {
            const src = $(this).attr("src");
            console.warn(`Image failed to load: ${src}`);
            $(this).attr("src", "../../media/phon.jpg"); // Fallback image
          });
        } catch (error) {
          console.error(`Error parsing HTML for ${pageUrl}:`, error);
          $contentArea.html(`<p>Error: Invalid HTML in ${pageUrl}</p>`);
          return;
        }

        if (jsFile) {
          $.getScript(jsFile)
            .done(() => {
              setTimeout(() => {
                const initFunction = `init${
                  pageKey.charAt(0).toUpperCase() + pageKey.slice(1)
                }`;
                if (typeof window[initFunction] === "function") {
                  try {
                    window[initFunction]();
                  } catch (error) {
                    console.error(`Error in ${initFunction}:`, error);
                  }
                } else {
                  console.warn(`No ${initFunction} found for ${pageKey}`);
                }
                $(document).trigger("pageScriptLoaded", {
                  page: pageKey,
                });
              }, 100);
            })
            .fail((jqxhr, settings, exception) => {
              console.error(`Failed to load JS: ${jsFile}`, exception);
              $(document).trigger("pageScriptLoaded", {
                page: pageKey,
              });
            });
        } else {
          $(document).trigger("pageScriptLoaded", {
            page: pageKey,
          });
        }
      },
      error: (xhr, status, error) => {
        $contentArea.html(`<p>Error: Failed to load ${pageUrl}</p>`);
        console.error(`Error loading page ${pageUrl}: ${error}`);
      },
    });
  }

  $links.on("click", (e) => {
    e.preventDefault();
    loadPage($(e.currentTarget));
  });

  const $defaultLink = $('.nav-link[data-page="Dashboard"]');
  if ($defaultLink.length) {
    loadPage($defaultLink);
  } else {
    console.error("Default dashboard link not found");
    $contentArea.html("<p>Error: Default page not found</p>");
  }
});
