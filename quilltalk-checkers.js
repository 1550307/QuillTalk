(function (global) {
    'use strict';

    var FILES = 'abcdefgh';
    var ALLOWED_PIECES = Object.freeze({
        wm: true,
        wk: true,
        bm: true,
        bk: true
    });

    function createInitialBoard() {
        var board = [];
        for (var row = 0; row < 8; row++) {
            var cells = [];
            for (var col = 0; col < 8; col++) {
                var piece = null;
                if (((row + col) % 2) === 1) {
                    if (row < 3) {
                        piece = 'bm';
                    } else if (row > 4) {
                        piece = 'wm';
                    }
                }
                cells.push(piece);
            }
            board.push(cells);
        }
        return board;
    }

    function cloneBoard(board) {
        return Array.isArray(board) ? board.map(function (row) {
            return Array.isArray(row) ? row.slice(0, 8) : Array(8).fill(null);
        }) : createInitialBoard();
    }

    function normalizePiece(piece) {
        var normalized = String(piece || '').trim().toLowerCase();
        return ALLOWED_PIECES[normalized] ? normalized : null;
    }

    function pieceColor(piece) {
        return piece ? String(piece).charAt(0) : '';
    }

    function pieceType(piece) {
        return piece ? String(piece).charAt(1) : '';
    }

    function opponentColor(color) {
        return color === 'b' ? 'w' : 'b';
    }

    function crownRowForColor(color) {
        return color === 'b' ? 7 : 0;
    }

    function isInsideBoard(row, col) {
        return row >= 0 && row < 8 && col >= 0 && col < 8;
    }

    function coordsToSquare(row, col) {
        if (!isInsideBoard(row, col)) {
            return '';
        }
        return FILES.charAt(col) + String(8 - row);
    }

    function squareToCoords(square) {
        var normalized = String(square || '').trim().toLowerCase();
        if (!/^[a-h][1-8]$/.test(normalized)) {
            return null;
        }
        return {
            row: 8 - parseInt(normalized.charAt(1), 10),
            col: FILES.indexOf(normalized.charAt(0))
        };
    }

    function getPieceAt(board, row, col) {
        if (!isInsideBoard(row, col)) {
            return null;
        }
        return normalizePiece(board[row] && board[row][col]);
    }

    function createInitialState() {
        return {
            version: 1,
            board: createInitialBoard(),
            turn: 'w',
            winnerColor: null,
            resultCode: null,
            resultLabel: null,
            lastMove: null,
            moveCount: 0
        };
    }

    function normalizeLastMove(value) {
        if (!value || typeof value !== 'object') {
            return null;
        }

        var from = String(value.from || '').trim().toLowerCase();
        var to = String(value.to || '').trim().toLowerCase();
        if (!/^[a-h][1-8]$/.test(from) || !/^[a-h][1-8]$/.test(to)) {
            return null;
        }

        var promotion = String(value.promotion || '').trim().toLowerCase();
        if (promotion !== 'k') {
            promotion = null;
        }

        return {
            from: from,
            to: to,
            piece: normalizePiece(value.piece),
            promotion: promotion,
            notation: String(value.notation || '').trim(),
            uci: String(value.uci || '').trim().toLowerCase(),
            playerColor: value.playerColor === 'b' ? 'b' : (value.playerColor === 'w' ? 'w' : null)
        };
    }

    function normalizeState(state) {
        if (!state || typeof state !== 'object') {
            return createInitialState();
        }

        var board = [];
        for (var row = 0; row < 8; row++) {
            var sourceRow = Array.isArray(state.board && state.board[row]) ? state.board[row] : [];
            var normalizedRow = [];
            for (var col = 0; col < 8; col++) {
                var piece = normalizePiece(sourceRow[col]);
                if (piece && ((row + col) % 2) === 0) {
                    piece = null;
                }
                normalizedRow.push(piece);
            }
            board.push(normalizedRow);
        }

        var winnerColor = state.winnerColor === 'b' ? 'b' : (state.winnerColor === 'w' ? 'w' : null);
        var resultCode = String(state.resultCode || '').trim() || null;
        var resultLabel = String(state.resultLabel || '').trim() || null;

        return {
            version: 1,
            board: board,
            turn: state.turn === 'b' ? 'b' : 'w',
            winnerColor: winnerColor,
            resultCode: resultCode,
            resultLabel: resultLabel,
            lastMove: normalizeLastMove(state.lastMove),
            moveCount: Math.max(0, Math.min(2000, Number(state.moveCount || 0) || 0))
        };
    }

    function getMovementDirections(piece) {
        if (pieceType(piece) === 'k') {
            return [
                [-1, -1],
                [-1, 1],
                [1, -1],
                [1, 1]
            ];
        }

        return pieceColor(piece) === 'b'
            ? [[1, -1], [1, 1]]
            : [[-1, -1], [-1, 1]];
    }

    function countPieces(board, color) {
        var total = 0;
        for (var row = 0; row < 8; row++) {
            for (var col = 0; col < 8; col++) {
                if (pieceColor(getPieceAt(board, row, col)) === color) {
                    total++;
                }
            }
        }
        return total;
    }

    function buildMove(originSquare, pathSquares, capturedSquares, piece, promotion) {
        var destination = pathSquares[pathSquares.length - 1] || originSquare;
        var isCapture = capturedSquares.length > 0;
        return {
            from: originSquare,
            to: destination,
            path: pathSquares.slice(),
            captures: capturedSquares.slice(),
            capture: isCapture,
            promotion: promotion || null,
            piece: piece,
            playerColor: pieceColor(piece),
            notation: pathSquares.join(isCapture ? 'x' : '-'),
            uci: originSquare + destination
        };
    }

    function getCaptureMovesFrom(board, row, col, piece, originSquare, pathSquares, capturedSquares) {
        var directions = getMovementDirections(piece);
        var results = [];
        var color = pieceColor(piece);

        directions.forEach(function (direction) {
            var jumpRow = row + direction[0];
            var jumpCol = col + direction[1];
            var landRow = row + (direction[0] * 2);
            var landCol = col + (direction[1] * 2);

            if (!isInsideBoard(jumpRow, jumpCol) || !isInsideBoard(landRow, landCol)) {
                return;
            }

            var jumpedPiece = getPieceAt(board, jumpRow, jumpCol);
            var landingPiece = getPieceAt(board, landRow, landCol);
            if (!jumpedPiece || pieceColor(jumpedPiece) === color || landingPiece) {
                return;
            }

            var nextBoard = cloneBoard(board);
            nextBoard[row][col] = null;
            nextBoard[jumpRow][jumpCol] = null;

            var movedPiece = piece;
            var promotion = null;
            if (pieceType(piece) === 'm' && landRow === crownRowForColor(color)) {
                movedPiece = color + 'k';
                promotion = 'k';
            }
            nextBoard[landRow][landCol] = movedPiece;

            var nextPathSquares = pathSquares.concat(coordsToSquare(landRow, landCol));
            var nextCapturedSquares = capturedSquares.concat(coordsToSquare(jumpRow, jumpCol));

            if (promotion) {
                results.push(buildMove(originSquare, nextPathSquares, nextCapturedSquares, piece, promotion));
                return;
            }

            var continuation = getCaptureMovesFrom(
                nextBoard,
                landRow,
                landCol,
                movedPiece,
                originSquare,
                nextPathSquares,
                nextCapturedSquares
            );

            if (continuation.length) {
                continuation.forEach(function (move) {
                    results.push(move);
                });
                return;
            }

            results.push(buildMove(originSquare, nextPathSquares, nextCapturedSquares, piece, null));
        });

        return results;
    }

    function getSimpleMovesFrom(board, row, col, piece) {
        var originSquare = coordsToSquare(row, col);
        return getMovementDirections(piece).reduce(function (moves, direction) {
            var targetRow = row + direction[0];
            var targetCol = col + direction[1];
            if (!isInsideBoard(targetRow, targetCol) || getPieceAt(board, targetRow, targetCol)) {
                return moves;
            }

            var promotion = null;
            if (pieceType(piece) === 'm' && targetRow === crownRowForColor(pieceColor(piece))) {
                promotion = 'k';
            }

            moves.push(buildMove(
                originSquare,
                [originSquare, coordsToSquare(targetRow, targetCol)],
                [],
                piece,
                promotion
            ));
            return moves;
        }, []);
    }

    function getLegalMovesForColor(state, color) {
        var normalizedState = normalizeState(state);
        var targetColor = color === 'b' ? 'b' : 'w';
        var captureMoves = [];

        for (var row = 0; row < 8; row++) {
            for (var col = 0; col < 8; col++) {
                var piece = getPieceAt(normalizedState.board, row, col);
                if (!piece || pieceColor(piece) !== targetColor) {
                    continue;
                }

                var pieceCaptureMoves = getCaptureMovesFrom(
                    normalizedState.board,
                    row,
                    col,
                    piece,
                    coordsToSquare(row, col),
                    [coordsToSquare(row, col)],
                    []
                );
                if (pieceCaptureMoves.length) {
                    captureMoves = captureMoves.concat(pieceCaptureMoves);
                }
            }
        }

        if (captureMoves.length) {
            return captureMoves;
        }

        var simpleMoves = [];
        for (var simpleRow = 0; simpleRow < 8; simpleRow++) {
            for (var simpleCol = 0; simpleCol < 8; simpleCol++) {
                var simplePiece = getPieceAt(normalizedState.board, simpleRow, simpleCol);
                if (!simplePiece || pieceColor(simplePiece) !== targetColor) {
                    continue;
                }

                simpleMoves = simpleMoves.concat(getSimpleMovesFrom(normalizedState.board, simpleRow, simpleCol, simplePiece));
            }
        }

        return simpleMoves;
    }

    function getLegalMovesForSquare(state, square) {
        var normalizedState = normalizeState(state);
        var normalizedSquare = String(square || '').trim().toLowerCase();
        if (!/^[a-h][1-8]$/.test(normalizedSquare)) {
            return [];
        }

        var coords = squareToCoords(normalizedSquare);
        if (!coords) {
            return [];
        }

        var piece = getPieceAt(normalizedState.board, coords.row, coords.col);
        if (!piece || pieceColor(piece) !== normalizedState.turn) {
            return [];
        }

        return getLegalMovesForColor(normalizedState, normalizedState.turn).filter(function (move) {
            return move.from === normalizedSquare;
        });
    }

    function applyMoveInternal(state, move) {
        var normalizedState = normalizeState(state);
        var board = cloneBoard(normalizedState.board);
        var fromCoords = squareToCoords(move.from);
        var toCoords = squareToCoords(move.to);
        if (!fromCoords || !toCoords) {
            return normalizedState;
        }

        var piece = getPieceAt(board, fromCoords.row, fromCoords.col);
        if (!piece) {
            return normalizedState;
        }

        var playerColor = pieceColor(piece);
        board[fromCoords.row][fromCoords.col] = null;
        (Array.isArray(move.captures) ? move.captures : []).forEach(function (captureSquare) {
            var captureCoords = squareToCoords(captureSquare);
            if (captureCoords) {
                board[captureCoords.row][captureCoords.col] = null;
            }
        });

        var movedPiece = move.promotion === 'k' ? playerColor + 'k' : piece;
        board[toCoords.row][toCoords.col] = movedPiece;

        var nextState = {
            version: 1,
            board: board,
            turn: opponentColor(playerColor),
            winnerColor: null,
            resultCode: null,
            resultLabel: null,
            lastMove: {
                from: move.from,
                to: move.to,
                piece: movedPiece,
                promotion: move.promotion || null,
                notation: move.notation || '',
                uci: move.uci || (move.from + move.to),
                playerColor: playerColor
            },
            moveCount: normalizedState.moveCount + 1
        };

        var enemyColor = nextState.turn;
        var enemyPieces = countPieces(board, enemyColor);
        var enemyMoves = getLegalMovesForColor(nextState, enemyColor);
        if (!enemyPieces) {
            nextState.winnerColor = playerColor;
            nextState.resultCode = 'capture_all';
            nextState.resultLabel = 'Captured all pieces';
        } else if (!enemyMoves.length) {
            nextState.winnerColor = playerColor;
            nextState.resultCode = 'no_moves';
            nextState.resultLabel = 'No legal moves';
        }

        return nextState;
    }

    function attemptMove(state, from, to) {
        var normalizedState = normalizeState(state);
        var normalizedFrom = String(from || '').trim().toLowerCase();
        var normalizedTo = String(to || '').trim().toLowerCase();
        if (!/^[a-h][1-8]$/.test(normalizedFrom) || !/^[a-h][1-8]$/.test(normalizedTo)) {
            return {
                ok: false,
                reason: 'Invalid checkers square.'
            };
        }

        var legalMoves = getLegalMovesForSquare(normalizedState, normalizedFrom).filter(function (move) {
            return move.to === normalizedTo;
        });
        if (!legalMoves.length) {
            return {
                ok: false,
                reason: 'That move is not legal.'
            };
        }

        var chosenMove = legalMoves[0];
        var nextState = applyMoveInternal(normalizedState, chosenMove);

        return {
            ok: true,
            state: nextState,
            move: nextState.lastMove
        };
    }

    function getPieceGlyph(piece) {
        var normalized = normalizePiece(piece);
        if (!normalized) {
            return '';
        }

        var glyphs = {
            wm: '\u26C0',
            wk: '\u26C1',
            bm: '\u26C2',
            bk: '\u26C3'
        };
        return glyphs[normalized] || '';
    }

    function getColorLabel(color) {
        return color === 'b' ? 'Black' : 'White';
    }

    global.QuillTalkCheckers = {
        createInitialState: createInitialState,
        normalizeState: normalizeState,
        getLegalMovesForSquare: getLegalMovesForSquare,
        getLegalMovesForColor: getLegalMovesForColor,
        attemptMove: attemptMove,
        getPieceGlyph: getPieceGlyph,
        getColorLabel: getColorLabel,
        coordsToSquare: coordsToSquare,
        squareToCoords: squareToCoords
    };
})(window);
